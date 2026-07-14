#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

#[cfg(not(windows))]
compile_error!("AITranscriber desktop builds are supported on Windows only.");

mod offline_whisper;
mod offline_whisper_worker;
mod speaker_diarization;
mod speaker_diarization_worker;

use serde::Serialize;
use std::fs::{File, OpenOptions};
use std::io::{Read, Write};
use std::net::TcpStream;
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::thread;
use std::time::Duration;
use tauri::{Emitter, Manager, State};
use tauri_plugin_dialog::DialogExt;

use std::os::windows::process::CommandExt;

const LARAVEL_PORT: &str = "8010";
const LARAVEL_URL: &str = "http://127.0.0.1:8010";
const LARAVEL_HOST_PORT: &str = "127.0.0.1:8010";
const STARTUP_ATTEMPTS: usize = 80;
const STARTUP_RETRY_DELAY: Duration = Duration::from_millis(250);
const CREATE_NO_WINDOW: u32 = 0x08000000;
const OFFLINE_WHISPER_MODEL: &str = "ggml-large-v3-turbo-q8_0.bin";

#[derive(Clone, Serialize)]
struct WhisperProgressEvent {
    progress_id: String,
    percent: i32,
}

#[derive(Clone)]
struct ResourceProfile {
    logical_processors: usize,
    whisper_threads: usize,
    total_memory_mb: u64,
    available_memory_mb: u64,
    whisper_memory_budget_mb: u64,
    gpu_available: bool,
    gpu_name: String,
    gpu_vram_mb: u64,
    whisper_gpu_vram_budget_mb: u64,
}

struct GpuProfile {
    name: String,
    vram_mb: u64,
}

fn whisper_thread_budget(logical_processors: usize) -> usize {
    let logical_processors = logical_processors.max(1);

    if logical_processors <= 2 {
        return 1;
    }

    let proportional_budget = (logical_processors * 3 / 5).max(1);
    let reserved_processors = if logical_processors >= 4 { 2 } else { 1 };

    proportional_budget.min(
        logical_processors
            .saturating_sub(reserved_processors)
            .max(1),
    )
}

fn whisper_memory_budget(total_memory_mb: u64) -> u64 {
    if total_memory_mb == 0 {
        return 0;
    }

    (total_memory_mb / 2).max(1)
}

fn whisper_gpu_vram_budget(total_vram_mb: u64) -> u64 {
    if total_vram_mb < 512 {
        return 0;
    }

    total_vram_mb.saturating_sub((total_vram_mb / 4).max(512))
}

fn resource_profile() -> ResourceProfile {
    let logical_processors = std::thread::available_parallelism()
        .map(|count| count.get())
        .unwrap_or(2)
        .max(1);
    let whisper_threads = whisper_thread_budget(logical_processors);
    let (total_memory_mb, available_memory_mb) = system_memory_mb().unwrap_or((0, 0));
    let whisper_memory_budget_mb = whisper_memory_budget(total_memory_mb);
    let gpu = detect_whisper_gpu();
    let gpu_vram_mb = gpu.as_ref().map(|profile| profile.vram_mb).unwrap_or(0);

    ResourceProfile {
        logical_processors,
        whisper_threads,
        total_memory_mb,
        available_memory_mb,
        whisper_memory_budget_mb,
        gpu_available: gpu.is_some(),
        gpu_name: gpu.map(|profile| profile.name).unwrap_or_default(),
        gpu_vram_mb,
        whisper_gpu_vram_budget_mb: whisper_gpu_vram_budget(gpu_vram_mb),
    }
}

#[cfg(feature = "vulkan")]
fn detect_whisper_gpu() -> Option<GpuProfile> {
    std::panic::catch_unwind(whisper_rs::vulkan::list_devices)
        .ok()?
        .into_iter()
        .filter_map(|device| {
            let vram_mb = (device.vram.total / 1_048_576) as u64;

            (vram_mb >= 512).then(|| GpuProfile {
                name: device.name,
                vram_mb,
            })
        })
        .max_by_key(|profile| profile.vram_mb)
}

#[cfg(not(feature = "vulkan"))]
fn detect_whisper_gpu() -> Option<GpuProfile> {
    None
}

fn system_memory_mb() -> Option<(u64, u64)> {
    use windows_sys::Win32::System::SystemInformation::{GlobalMemoryStatusEx, MEMORYSTATUSEX};

    let mut status: MEMORYSTATUSEX = unsafe { std::mem::zeroed() };
    status.dwLength = std::mem::size_of::<MEMORYSTATUSEX>() as u32;

    if unsafe { GlobalMemoryStatusEx(&mut status) } == 0 {
        return None;
    }

    Some((
        status.ullTotalPhys / 1_048_576,
        status.ullAvailPhys / 1_048_576,
    ))
}

fn lower_offline_worker_priority() {
    use windows_sys::Win32::System::Threading::{
        GetCurrentProcess, SetPriorityClass, BELOW_NORMAL_PRIORITY_CLASS,
    };

    unsafe {
        SetPriorityClass(GetCurrentProcess(), BELOW_NORMAL_PRIORITY_CLASS);
    }
}

fn run_offline_whisper_cli() -> bool {
    let arguments = std::env::args().skip(1).collect::<Vec<_>>();

    if !arguments
        .iter()
        .any(|argument| argument == "--offline-whisper")
    {
        return false;
    }

    lower_offline_worker_priority();

    let value_after = |flag: &str| -> Option<&str> {
        arguments
            .iter()
            .position(|argument| argument == flag)
            .and_then(|index| arguments.get(index + 1))
            .map(String::as_str)
    };
    let output_path = value_after("--output").map(PathBuf::from);
    let fallback_threads = || {
        whisper_thread_budget(
            std::thread::available_parallelism()
                .map(|count| count.get())
                .unwrap_or(2),
        )
    };
    let thread_budget = value_after("--threads")
        .and_then(|value| value.parse::<usize>().ok())
        .unwrap_or_else(fallback_threads);
    let use_gpu = arguments.iter().any(|argument| argument == "--gpu");
    let result = match (value_after("--model"), value_after("--audio")) {
        (Some(model), Some(audio)) => offline_whisper::transcribe(
            std::path::Path::new(model),
            std::path::Path::new(audio),
            value_after("--language"),
            thread_budget,
            use_gpu,
        ),
        _ => Err("offline Whisper requires --model and --audio paths".to_string()),
    };
    let (payload, exit_code) = match result {
        Ok(transcription) => (
            serde_json::to_vec(&transcription)
                .unwrap_or_else(|_| br#"{"error":"failed to encode transcription"}"#.to_vec()),
            0,
        ),
        Err(error) => (
            serde_json::to_vec(&serde_json::json!({ "error": error }))
                .unwrap_or_else(|_| br#"{"error":"offline transcription failed"}"#.to_vec()),
            1,
        ),
    };

    if let Some(output_path) = output_path {
        if std::fs::write(output_path, payload).is_err() {
            std::process::exit(1);
        }
    } else {
        let _ = std::io::stdout().write_all(&payload);
    }

    if exit_code != 0 {
        std::process::exit(exit_code);
    }

    true
}

struct LaravelProcesses {
    server: Mutex<Option<Child>>,
    queue_worker: Mutex<Option<Child>>,
}

struct LaravelPaths {
    project_dir: PathBuf,
    database_path: PathBuf,
    storage_path: PathBuf,
    startup_log_path: PathBuf,
    resources: ResourceProfile,
}

#[derive(Serialize)]
struct AudioFileSelection {
    path: String,
    name: String,
    size: u64,
    duration_ms: Option<u64>,
}

fn bundled_project_dir(app: &tauri::AppHandle) -> Result<std::path::PathBuf, String> {
    if cfg!(debug_assertions) {
        let current_dir = std::env::current_dir().map_err(|error| error.to_string())?;

        if current_dir.ends_with("src-tauri") {
            return current_dir
                .parent()
                .map(std::path::Path::to_path_buf)
                .ok_or_else(|| "failed to resolve project directory".to_string());
        }

        return Ok(current_dir);
    }

    app.path()
        .resource_dir()
        .map_err(|error| format!("failed to resolve bundled resources: {error}"))
}

fn writable_paths(app: &tauri::AppHandle) -> Result<(PathBuf, PathBuf), String> {
    if cfg!(debug_assertions) {
        let project_dir = bundled_project_dir(app)?;

        return Ok((
            project_dir.join("database").join("database.sqlite"),
            project_dir.join("storage"),
        ));
    }

    let app_data_dir = app
        .path()
        .app_data_dir()
        .map_err(|error| format!("failed to resolve app data directory: {error}"))?;

    Ok((
        app_data_dir.join("database.sqlite"),
        app_data_dir.join("storage"),
    ))
}

fn laravel_paths(app: &tauri::AppHandle) -> Result<LaravelPaths, String> {
    let project_dir = bundled_project_dir(app)?;
    let (database_path, storage_path) = writable_paths(app)?;
    let startup_log_path = storage_path.join("logs").join("tauri-startup.log");

    Ok(LaravelPaths {
        project_dir,
        database_path,
        storage_path,
        startup_log_path,
        resources: resource_profile(),
    })
}

fn ensure_runtime_storage(paths: &LaravelPaths) -> Result<(), String> {
    if let Some(database_dir) = paths.database_path.parent() {
        std::fs::create_dir_all(database_dir)
            .map_err(|error| format!("failed to create database directory: {error}"))?;
    }

    if !paths.database_path.exists() {
        let bundled_database_path = paths.project_dir.join("database").join("database.sqlite");

        if bundled_database_path.is_file() {
            std::fs::copy(&bundled_database_path, &paths.database_path)
                .map_err(|error| format!("failed to copy bundled SQLite database: {error}"))?;
        } else {
            std::fs::File::create(&paths.database_path)
                .map_err(|error| format!("failed to create SQLite database: {error}"))?;
        }
    }

    for directory in [
        paths.storage_path.join("app").join("private"),
        paths.storage_path.join("app").join("public"),
        paths
            .storage_path
            .join("framework")
            .join("cache")
            .join("data"),
        paths.storage_path.join("framework").join("sessions"),
        paths.storage_path.join("framework").join("testing"),
        paths.storage_path.join("framework").join("views"),
        paths.storage_path.join("logs"),
    ] {
        std::fs::create_dir_all(&directory)
            .map_err(|error| format!("failed to create storage directory: {error}"))?;
    }

    Ok(())
}

fn reset_startup_log(paths: &LaravelPaths) -> Result<File, String> {
    if let Some(log_dir) = paths.startup_log_path.parent() {
        std::fs::create_dir_all(log_dir)
            .map_err(|error| format!("failed to create startup log directory: {error}"))?;
    }

    let mut file = File::create(&paths.startup_log_path)
        .map_err(|error| format!("failed to create startup log: {error}"))?;

    writeln!(
        file,
        "AITranscriber startup log\nProject: {}\nDatabase: {}\nStorage: {}\nCPU: {} logical processors; Whisper: {} threads\nMemory: {} MB total; {} MB available; Whisper budget: {} MB\nGPU: {}; VRAM: {} MB; Whisper budget: {} MB\n",
        paths.project_dir.display(),
        paths.database_path.display(),
        paths.storage_path.display(),
        paths.resources.logical_processors,
        paths.resources.whisper_threads,
        paths.resources.total_memory_mb,
        paths.resources.available_memory_mb,
        paths.resources.whisper_memory_budget_mb,
        if paths.resources.gpu_available { paths.resources.gpu_name.as_str() } else { "CPU fallback" },
        paths.resources.gpu_vram_mb,
        paths.resources.whisper_gpu_vram_budget_mb,
    )
    .map_err(|error| format!("failed to write startup log header: {error}"))?;

    Ok(file)
}

fn startup_log_handle(paths: &LaravelPaths) -> Result<File, String> {
    OpenOptions::new()
        .create(true)
        .append(true)
        .open(&paths.startup_log_path)
        .map_err(|error| format!("failed to open startup log: {error}"))
}

fn append_startup_log(paths: &LaravelPaths, message: &str) {
    if let Ok(mut file) = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&paths.startup_log_path)
    {
        let _ = writeln!(file, "{message}");
    }
}

fn laravel_command(php_path: PathBuf, artisan_path: PathBuf, paths: &LaravelPaths) -> Command {
    let mut command = Command::new(php_path);
    let database_env = env_path(&paths.database_path);
    let storage_env = env_path(&paths.storage_path);
    let process_temp_directory = paths.storage_path.join("framework").join("process-temp");
    let _ = std::fs::create_dir_all(&process_temp_directory);
    let process_temp_env = env_path(&process_temp_directory);

    let executable = std::env::current_exe().ok();
    let whisper_model_directory = paths
        .storage_path
        .join("app")
        .join("private")
        .join("whisper")
        .join("models");
    let whisper_model = whisper_model_directory.join(OFFLINE_WHISPER_MODEL);
    let sherpa_model_directory = paths.project_dir.join("sherpa").join("models");
    command
        .arg(artisan_path)
        .current_dir(&paths.project_dir)
        .env("DB_DATABASE", database_env)
        .env("APP_STORAGE_PATH", storage_env)
        .env("TMP", process_temp_env.clone())
        .env("TEMP", process_temp_env.clone())
        .env("TMPDIR", process_temp_env)
        .env("APP_ENV", "production")
        .env("APP_DEBUG", "false")
        .env(
            "AI_TRANSCRIBER_WHISPER_THREADS",
            paths.resources.whisper_threads.to_string(),
        )
        .env(
            "AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB",
            paths.resources.whisper_memory_budget_mb.to_string(),
        )
        .env(
            "AI_TRANSCRIBER_LOGICAL_PROCESSORS",
            paths.resources.logical_processors.to_string(),
        )
        .env(
            "AI_TRANSCRIBER_TOTAL_MEMORY_MB",
            paths.resources.total_memory_mb.to_string(),
        )
        .env(
            "AI_TRANSCRIBER_AVAILABLE_MEMORY_MB",
            paths.resources.available_memory_mb.to_string(),
        )
        .env(
            "AI_TRANSCRIBER_GPU_AVAILABLE",
            if paths.resources.gpu_available {
                "true"
            } else {
                "false"
            },
        )
        .env("AI_TRANSCRIBER_GPU_NAME", &paths.resources.gpu_name)
        .env(
            "AI_TRANSCRIBER_GPU_VRAM_MB",
            paths.resources.gpu_vram_mb.to_string(),
        )
        .env(
            "AI_TRANSCRIBER_WHISPER_GPU_VRAM_BUDGET_MB",
            paths.resources.whisper_gpu_vram_budget_mb.to_string(),
        )
        .env("WHISPER_MODEL_PATH", env_path(&whisper_model))
        .env(
            "WHISPER_MODEL_DIRECTORY",
            env_path(&whisper_model_directory),
        )
        .env(
            "SHERPA_DIARIZATION_MODEL_DIRECTORY",
            env_path(&sherpa_model_directory),
        );

    if let Some(executable) = executable {
        command.env("AI_TRANSCRIBER_EXECUTABLE", env_path(&executable));
    }

    let php_dir = paths.project_dir.join("php");
    let ca_bundle_path = php_dir.join("extras").join("ssl").join("cacert.pem");
    let ca_bundle_env = env_path(&ca_bundle_path);

    command
        .env("PHPRC", env_path(&php_dir))
        .env("PHP_INI_SCAN_DIR", "")
        .env("CURL_CA_BUNDLE", ca_bundle_env.clone())
        .env("AI_TRANSCRIBER_CA_BUNDLE", ca_bundle_env.clone())
        .env("SSL_CERT_FILE", ca_bundle_env);

    command
}

fn env_path(path: &std::path::Path) -> String {
    let path = path.display().to_string();

    path.strip_prefix(r"\\?\").unwrap_or(&path).to_string()
}

fn run_migrations(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    run_artisan(
        paths,
        php_path,
        artisan_path,
        &["migrate", "--force", "--no-interaction"],
        "database migrations",
    )
}

fn sync_bundled_default_settings(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    run_artisan(
        paths,
        php_path,
        artisan_path,
        &["app:sync-bundled-default-settings"],
        "bundled default settings sync",
    )
}

fn clear_compiled_views(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    run_artisan(
        paths,
        php_path,
        artisan_path,
        &["view:clear", "--no-interaction"],
        "compiled view cleanup",
    )
}

fn clear_pending_queue(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    run_artisan(
        paths,
        php_path,
        artisan_path,
        &[
            "queue:clear",
            "database",
            "--queue=default",
            "--force",
            "--no-interaction",
        ],
        "pending queue cleanup",
    )
}

fn run_artisan(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
    args: &[&str],
    label: &str,
) -> Result<(), String> {
    let mut command = laravel_command(php_path, artisan_path, paths);

    command
        .args(args)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to run {label}: {error}"))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        let stdout = String::from_utf8_lossy(&output.stdout);
        let details = if stderr.trim().is_empty() {
            stdout.trim()
        } else {
            stderr.trim()
        };

        return Err(format!("{label} failed: {details}"));
    }

    Ok(())
}

fn listening_processes_on_laravel_port() -> Result<Vec<u32>, String> {
    let mut command = Command::new("netstat");
    command.args(["-ano", "-p", "tcp"]);
    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to inspect port {LARAVEL_PORT}: {error}"))?;

    if !output.status.success() {
        return Err(format!(
            "netstat failed while checking port {LARAVEL_PORT}: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut process_ids = Vec::new();

    for line in stdout.lines() {
        if !line.contains("LISTENING") || !line.contains(&format!(":{LARAVEL_PORT}")) {
            continue;
        }

        let Some(pid_text) = line.split_whitespace().last() else {
            continue;
        };

        let Ok(process_id) = pid_text.parse::<u32>() else {
            continue;
        };

        if process_id != 0 && !process_ids.contains(&process_id) {
            process_ids.push(process_id);
        }
    }

    Ok(process_ids)
}

fn kill_process_tree(process_id: u32) -> Result<(), String> {
    let mut command = Command::new("taskkill");
    command.args(["/PID", &process_id.to_string(), "/F", "/T"]);
    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to stop process {process_id}: {error}"))?;

    if output.status.success() {
        return Ok(());
    }

    let stderr = String::from_utf8_lossy(&output.stderr);
    let stdout = String::from_utf8_lossy(&output.stdout);
    let details = if stderr.trim().is_empty() {
        stdout.trim()
    } else {
        stderr.trim()
    };

    Err(format!("failed to stop process {process_id}: {details}"))
}

fn force_clear_laravel_port(paths: &LaravelPaths) -> Result<(), String> {
    for attempt in 1..=5 {
        let process_ids = listening_processes_on_laravel_port()?;

        if process_ids.is_empty() {
            append_startup_log(paths, &format!("Port {LARAVEL_PORT} is free."));
            return Ok(());
        }

        append_startup_log(
            paths,
            &format!(
                "Port {LARAVEL_PORT} is occupied by PID(s): {}. Stopping them before launching bundled PHP.",
                process_ids
                    .iter()
                    .map(u32::to_string)
                    .collect::<Vec<String>>()
                    .join(", ")
            ),
        );

        for process_id in process_ids {
            kill_process_tree(process_id)?;
            append_startup_log(paths, &format!("Stopped process tree {process_id}."));
        }

        thread::sleep(Duration::from_millis(350));

        if attempt == 5 {
            break;
        }
    }

    if listening_processes_on_laravel_port()?.is_empty() {
        Ok(())
    } else {
        Err(format!(
            "port {LARAVEL_PORT} is still in use after stopping existing PHP servers."
        ))
    }
}

fn start_laravel(app: &tauri::AppHandle) -> Result<(), String> {
    let paths = laravel_paths(app)?;
    ensure_runtime_storage(&paths)?;
    let startup_log = startup_log_handle(&paths)?;

    let php_path = paths.project_dir.join("php").join("php.exe");
    let artisan_path = paths.project_dir.join("artisan");

    if !php_path.is_file() {
        return Err(format!("missing PHP runtime: {}", php_path.display()));
    }

    if !artisan_path.is_file() {
        return Err(format!(
            "missing Laravel artisan file: {}",
            artisan_path.display()
        ));
    }

    run_migrations(&paths, php_path.clone(), artisan_path.clone())?;
    append_startup_log(&paths, "Database migrations completed.");
    sync_bundled_default_settings(&paths, php_path.clone(), artisan_path.clone())?;
    append_startup_log(&paths, "Bundled default settings sync completed.");
    clear_compiled_views(&paths, php_path.clone(), artisan_path.clone())?;
    append_startup_log(&paths, "Compiled Blade views cleared.");
    clear_pending_queue(&paths, php_path.clone(), artisan_path.clone())?;
    append_startup_log(&paths, "Pending queue jobs cleared before worker startup.");

    let mut command = laravel_command(php_path, artisan_path, &paths);
    let stderr_log = startup_log
        .try_clone()
        .map_err(|error| format!("failed to clone startup log handle: {error}"))?;

    command
        .arg("serve")
        .arg("--host=127.0.0.1")
        .arg(format!("--port={LARAVEL_PORT}"))
        .stdout(Stdio::from(startup_log))
        .stderr(Stdio::from(stderr_log));

    command.creation_flags(CREATE_NO_WINDOW);

    let server = command
        .spawn()
        .map_err(|error| format!("failed to start Laravel server: {error}"))?;

    let queue_stdout = startup_log_handle(&paths)?;
    let queue_stderr = queue_stdout
        .try_clone()
        .map_err(|error| format!("failed to clone queue worker log handle: {error}"))?;
    let mut queue_command = laravel_command(
        paths.project_dir.join("php").join("php.exe"),
        paths.project_dir.join("artisan"),
        &paths,
    );
    queue_command
        .arg("queue:work")
        .arg("--sleep=1")
        .arg("--tries=3")
        .arg("--timeout=0")
        .stdout(Stdio::from(queue_stdout))
        .stderr(Stdio::from(queue_stderr));
    queue_command.creation_flags(CREATE_NO_WINDOW);

    let queue_worker = match queue_command.spawn() {
        Ok(child) => child,
        Err(error) => {
            let mut server = server;
            let _ = server.kill();
            let _ = server.wait();

            return Err(format!("failed to start Laravel queue worker: {error}"));
        }
    };

    let state: State<LaravelProcesses> = app.state();
    *state.server.lock().map_err(|error| error.to_string())? = Some(server);
    *state
        .queue_worker
        .lock()
        .map_err(|error| error.to_string())? = Some(queue_worker);
    append_startup_log(&paths, "Laravel queue worker started.");

    Ok(())
}

fn laravel_http_response() -> Result<(u16, String), String> {
    let mut stream = TcpStream::connect(LARAVEL_HOST_PORT)
        .map_err(|error| format!("Laravel port is not ready: {error}"))?;

    let timeout = Some(Duration::from_secs(3));
    stream
        .set_read_timeout(timeout)
        .map_err(|error| format!("failed to set Laravel read timeout: {error}"))?;
    stream
        .set_write_timeout(timeout)
        .map_err(|error| format!("failed to set Laravel write timeout: {error}"))?;

    stream
        .write_all(
            format!(
                "GET / HTTP/1.1\r\nHost: 127.0.0.1:{LARAVEL_PORT}\r\nConnection: close\r\n\r\n"
            )
            .as_bytes(),
        )
        .map_err(|error| format!("failed to send Laravel readiness request: {error}"))?;

    let mut response = String::new();
    stream
        .read_to_string(&mut response)
        .map_err(|error| format!("failed to read Laravel readiness response: {error}"))?;

    let status = response
        .lines()
        .next()
        .and_then(|line| line.split_whitespace().nth(1))
        .and_then(|status| status.parse::<u16>().ok())
        .ok_or_else(|| "Laravel returned an invalid HTTP response.".to_string())?;

    Ok((status, response))
}

fn wait_for_laravel(paths: &LaravelPaths) -> Result<(), String> {
    let mut last_error = "Laravel server did not respond yet.".to_string();

    for _ in 0..STARTUP_ATTEMPTS {
        match laravel_http_response() {
            Ok((status, _)) if (200..400).contains(&status) => return Ok(()),
            Ok((status, response)) => {
                last_error = format!(
                    "Laravel returned HTTP {status} during startup.{}",
                    recent_startup_log(paths),
                );

                if status >= 500 && response.trim().is_empty() {
                    last_error.push_str("\nThe server response was empty.");
                }
            }
            Err(error) => {
                last_error = error;
            }
        }

        thread::sleep(STARTUP_RETRY_DELAY);
    }

    Err(last_error)
}

fn stop_laravel(app: &tauri::AppHandle) {
    let state: State<LaravelProcesses> = app.state();

    for process in [&state.queue_worker, &state.server] {
        let child_process = {
            let Ok(mut guard) = process.lock() else {
                continue;
            };

            guard.take()
        };

        if let Some(mut child) = child_process {
            let _ = child.kill();
            let _ = child.wait();
        }
    }
}

fn recent_startup_log(paths: &LaravelPaths) -> String {
    let Ok(contents) = std::fs::read_to_string(&paths.startup_log_path) else {
        return format!(
            "\nNo Tauri startup log was found at {}.",
            paths.startup_log_path.display()
        );
    };

    let lines: Vec<&str> = contents.lines().rev().take(24).collect();

    if lines.is_empty() {
        return format!(
            "\nTauri startup log is empty at {}.",
            paths.startup_log_path.display()
        );
    }

    let recent_lines = lines.into_iter().rev().collect::<Vec<&str>>().join("\n");

    format!(
        "\nRecent PHP startup output from {}:\n{}",
        paths.startup_log_path.display(),
        recent_lines
    )
}

fn escape_html(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

fn show_startup_error(app: &tauri::AppHandle, message: &str) {
    if let Some(window) = app.get_webview_window("main") {
        let escaped = escape_html(message);
        let html = format!(
            "<main style=\"font-family:Segoe UI,sans-serif;padding:24px;color:#e2e8f0;background:#071018;min-height:100vh\"><h1 style=\"font-size:22px;margin:0 0 12px\">AITranscriber could not start</h1><p style=\"line-height:1.6;margin:0 0 16px;color:#94a3b8\">The desktop app waits for its own PHP server to return a successful page before opening the workspace.</p><pre style=\"white-space:pre-wrap;line-height:1.5;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);border-radius:8px;padding:14px;color:#e2e8f0\">{}</pre></main>",
            escaped
        );
        let script = format!(
            "document.body.innerHTML = {};",
            serde_json::to_string(&html).unwrap_or_else(|_| "\"Startup error\"".to_string())
        );

        let _ = window.eval(&script);
        let _ = window.show();
    }
}

fn show_laravel_window(app: &tauri::AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.eval(&format!("window.location.replace('{LARAVEL_URL}')"));
        let _ = window.show();
        let _ = window.set_focus();
    }
}

fn bootstrap_laravel(app: &tauri::AppHandle) -> Result<(), String> {
    let paths = laravel_paths(app)?;
    ensure_runtime_storage(&paths)?;
    reset_startup_log(&paths)?;

    if cfg!(debug_assertions) && TcpStream::connect(LARAVEL_HOST_PORT).is_ok() {
        append_startup_log(
            &paths,
            &format!("Debug mode detected an existing dev server on port {LARAVEL_PORT}."),
        );
        return Ok(());
    }

    force_clear_laravel_port(&paths)?;
    start_laravel(app)?;
    wait_for_laravel(&paths)
}

#[tauri::command]
fn open_external_url(url: String) -> Result<(), String> {
    let url = url.trim().to_string();

    if !(url.starts_with("https://") || url.starts_with("http://")) {
        return Err("Only http and https links can be opened externally.".to_string());
    }

    if url.chars().any(char::is_control) {
        return Err("The external link is not valid.".to_string());
    }

    let mut command = Command::new("rundll32");
    command.args(["url.dll,FileProtocolHandler", &url]);
    command.creation_flags(CREATE_NO_WINDOW);
    command
        .spawn()
        .map_err(|error| format!("failed to open external link: {error}"))?;

    Ok(())
}

#[tauri::command]
fn save_text_export(
    app: tauri::AppHandle,
    filename: Option<String>,
    content: String,
    default_extension: Option<String>,
    filter_name: Option<String>,
    filter_extensions: Option<Vec<String>>,
) -> Result<Option<String>, String> {
    save_export_content(
        &app,
        filename.as_deref(),
        content,
        default_extension.as_deref(),
        filter_name.as_deref(),
        filter_extensions.as_deref(),
    )
}

#[tauri::command]
fn save_text_export_with_dialog(
    app: tauri::AppHandle,
    filename: Option<String>,
    content: String,
    default_extension: Option<String>,
    filter_name: Option<String>,
    filter_extensions: Option<Vec<String>>,
) -> Result<Option<String>, String> {
    save_export_content(
        &app,
        filename.as_deref(),
        content,
        default_extension.as_deref(),
        filter_name.as_deref(),
        filter_extensions.as_deref(),
    )
}

#[tauri::command]
fn save_transcript_export_with_dialog(
    app: tauri::AppHandle,
    filename: Option<String>,
    content: String,
    default_extension: Option<String>,
    filter_name: Option<String>,
    filter_extensions: Option<Vec<String>>,
) -> Result<Option<String>, String> {
    save_export_content(
        &app,
        filename.as_deref(),
        content,
        default_extension.as_deref(),
        filter_name.as_deref(),
        filter_extensions.as_deref(),
    )
}

fn save_export_content(
    app: &tauri::AppHandle,
    filename: Option<&str>,
    content: String,
    default_extension: Option<&str>,
    filter_name: Option<&str>,
    filter_extensions: Option<&[String]>,
) -> Result<Option<String>, String> {
    let Some(export_path) = choose_transcript_export_path(
        app,
        filename,
        default_extension,
        filter_name,
        filter_extensions,
    )?
    else {
        return Ok(None);
    };

    std::fs::write(&export_path, content.as_bytes())
        .map_err(|error| format!("failed to save transcript export: {error}"))?;

    Ok(Some(export_path.display().to_string()))
}

fn choose_transcript_export_path(
    app: &tauri::AppHandle,
    filename: Option<&str>,
    default_extension: Option<&str>,
    filter_name: Option<&str>,
    filter_extensions: Option<&[String]>,
) -> Result<Option<PathBuf>, String> {
    let extension = default_extension
        .map(str::trim)
        .filter(|value| {
            !value.is_empty()
                && value
                    .chars()
                    .all(|character| character.is_ascii_alphanumeric())
        })
        .unwrap_or("txt");
    let filter_title = filter_name
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .unwrap_or("Transcript files");
    let mut extensions: Vec<&str> = filter_extensions
        .unwrap_or(&[])
        .iter()
        .map(String::as_str)
        .filter(|value| {
            !value.is_empty()
                && value
                    .chars()
                    .all(|character| character.is_ascii_alphanumeric())
        })
        .collect();

    if extensions.is_empty() {
        extensions.push(extension);
    }

    let mut dialog = app
        .dialog()
        .file()
        .set_title("Save transcript export")
        .add_filter(filter_title, &extensions);

    if let Some(name) = filename
        .map(str::trim)
        .filter(|value| !value.is_empty() && !value.chars().any(char::is_control))
    {
        dialog = dialog.set_file_name(name);
    }

    let Some(file_path) = dialog.blocking_save_file() else {
        return Ok(None);
    };

    let mut path = file_path
        .into_path()
        .map_err(|error| format!("failed to read selected export path: {error}"))?;

    if path.extension().is_none() {
        path.set_extension(extension);
    }

    Ok(Some(path))
}

#[tauri::command]
fn choose_audio_file(app: tauri::AppHandle) -> Result<Option<AudioFileSelection>, String> {
    let Some(file_path) = app
        .dialog()
        .file()
        .set_title("Choose audio file")
        .add_filter(
            "Audio files",
            &[
                "wav", "mp3", "m4a", "aac", "ogg", "oga", "flac", "webm", "wma", "mp4",
            ],
        )
        .blocking_pick_file()
    else {
        return Ok(None);
    };

    let path = file_path
        .into_path()
        .map_err(|error| format!("failed to read selected audio path: {error}"))?;
    let metadata = std::fs::metadata(&path)
        .map_err(|error| format!("failed to inspect selected audio file: {error}"))?;

    if !metadata.is_file() {
        return Err("Choose a valid audio file.".to_string());
    }

    let name = path
        .file_name()
        .and_then(|value| value.to_str())
        .unwrap_or("audio")
        .to_string();
    let duration_ms = probe_audio_duration_ms(&app, &path).ok();

    Ok(Some(AudioFileSelection {
        path: env_path(&path),
        name,
        size: metadata.len(),
        duration_ms,
    }))
}

fn probe_audio_duration_ms(app: &tauri::AppHandle, path: &std::path::Path) -> Result<u64, String> {
    let ffprobe_path = bundled_project_dir(app)?
        .join("ffmpeg")
        .join("bin")
        .join("ffprobe.exe");

    if !ffprobe_path.is_file() {
        return Err("ffprobe is missing.".to_string());
    }

    let audio_path = env_path(path);
    let mut command = Command::new(ffprobe_path);
    command.args([
        "-v",
        "error",
        "-show_entries",
        "format=duration",
        "-of",
        "default=noprint_wrappers=1:nokey=1",
        audio_path.as_str(),
    ]);

    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to inspect audio duration: {error}"))?;

    if !output.status.success() {
        return Err("ffprobe could not read the audio duration.".to_string());
    }

    let duration_seconds = String::from_utf8_lossy(&output.stdout)
        .trim()
        .parse::<f64>()
        .map_err(|error| format!("invalid audio duration: {error}"))?;

    if !duration_seconds.is_finite() || duration_seconds <= 0.0 {
        return Err("audio duration is unavailable.".to_string());
    }

    Ok((duration_seconds * 1000.0).round().max(1.0) as u64)
}

fn wait_for_update_archive(archive_path: PathBuf) -> Result<PathBuf, String> {
    let deadline = std::time::Instant::now() + Duration::from_secs(8);

    loop {
        match std::fs::canonicalize(&archive_path) {
            Ok(path) => return Ok(path),
            Err(_) => {}
        }

        if std::time::Instant::now() >= deadline {
            break;
        }

        thread::sleep(Duration::from_millis(150));
    }

    Err(format!(
        "failed to locate downloaded update ZIP at {}",
        archive_path.display(),
    ))
}

#[tauri::command]
fn install_update(app: tauri::AppHandle, archive_path: String) -> Result<(), String> {
    let paths = laravel_paths(&app)?;
    let archive = wait_for_update_archive(PathBuf::from(archive_path))?;
    let update_directory = paths
        .storage_path
        .join("app")
        .join("private")
        .join("app-updates");
    let canonical_update_directory = std::fs::canonicalize(&update_directory)
        .map_err(|error| format!("failed to locate local update directory: {error}"))?;

    if !archive.starts_with(&canonical_update_directory)
        || archive.extension().and_then(|value| value.to_str()) != Some("zip")
    {
        return Err("The update archive path is not allowed.".to_string());
    }

    let install_directory = std::fs::canonicalize(&paths.project_dir)
        .map_err(|error| format!("failed to locate application directory: {error}"))?;
    let permission_probe = install_directory.join(format!(
        ".aitranscriber-update-write-test-{}.tmp",
        std::process::id()
    ));

    std::fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(&permission_probe)
            .map_err(|error| {
                format!(
                    "AITranscriber cannot update files in {}. Reinstall it for the current user or grant this account write access: {error}",
                    install_directory.display()
                )
            })?;
    std::fs::remove_file(&permission_probe).map_err(|error| {
        format!(
            "failed to remove the update permission check in {}: {error}",
            install_directory.display()
        )
    })?;
    let executable = std::env::current_exe()
        .map_err(|error| format!("failed to locate AITranscriber executable: {error}"))?;
    let updater_script = update_directory.join("install-update.ps1");
    let updater_log = update_directory.join("install-update.log");
    let script = r#"param(
    [Parameter(Mandatory=$true)][string]$Archive,
    [Parameter(Mandatory=$true)][string]$InstallDirectory,
    [Parameter(Mandatory=$true)][string]$Executable,
    [Parameter(Mandatory=$true)][int]$ParentProcessId,
    [Parameter(Mandatory=$true)][string]$LogPath
)
$ErrorActionPreference = 'Stop'
try {
    Wait-Process -Id $ParentProcessId -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 750
    Expand-Archive -LiteralPath $Archive -DestinationPath $InstallDirectory -Force
    Remove-Item -LiteralPath $Archive -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $LogPath -Force -ErrorAction SilentlyContinue
    Start-Process -FilePath $Executable -WorkingDirectory $InstallDirectory
} catch {
    "$(Get-Date -Format o) Update installation failed: $($_.Exception.Message)" | Out-File -LiteralPath $LogPath -Encoding utf8 -Append
    if (Test-Path -LiteralPath $Executable) {
        Start-Process -FilePath $Executable -WorkingDirectory $InstallDirectory
    }
    exit 1
} finally {
    Remove-Item -LiteralPath $PSCommandPath -Force -ErrorAction SilentlyContinue
}
"#;

    std::fs::write(&updater_script, script)
        .map_err(|error| format!("failed to prepare update installer: {error}"))?;

    let mut command = Command::new("powershell.exe");
    command.args([
        "-NoProfile",
        "-NonInteractive",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
    ]);
    command.arg(&updater_script);
    command.arg("-Archive").arg(&archive);
    command.arg("-InstallDirectory").arg(&install_directory);
    command.arg("-Executable").arg(&executable);
    command
        .arg("-ParentProcessId")
        .arg(std::process::id().to_string());
    command.arg("-LogPath").arg(&updater_log);
    command.creation_flags(CREATE_NO_WINDOW);
    command
        .spawn()
        .map_err(|error| format!("failed to start update installer: {error}"))?;

    stop_laravel(&app);
    app.exit(0);

    Ok(())
}

#[tauri::command]
fn cancel_offline_whisper(progress_id: String) -> bool {
    offline_whisper_worker::cancel(&progress_id)
}

fn main() {
    if run_offline_whisper_cli() {
        return;
    }

    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .manage(LaravelProcesses {
            server: Mutex::new(None),
            queue_worker: Mutex::new(None),
        })
        .invoke_handler(tauri::generate_handler![
            open_external_url,
            save_text_export,
            save_text_export_with_dialog,
            save_transcript_export_with_dialog,
            choose_audio_file,
            cancel_offline_whisper,
            install_update
        ])
        .setup(|app| {
            let handle = app.handle().clone();

            if let Ok(paths) = laravel_paths(&handle) {
                if let Err(error) = offline_whisper_worker::start(
                    &paths.storage_path,
                    paths.resources.whisper_threads,
                    {
                        let progress_handle = handle.clone();

                        move |progress_id, percent| {
                            let _ = progress_handle.emit(
                                "offline-whisper-progress",
                                WhisperProgressEvent {
                                    progress_id,
                                    percent,
                                },
                            );
                        }
                    },
                ) {
                    append_startup_log(
                        &paths,
                        &format!("Offline Whisper worker unavailable: {error}"),
                    );
                }

                if let Err(error) = speaker_diarization_worker::start(
                    &paths.storage_path,
                    paths.resources.whisper_threads.min(4),
                ) {
                    append_startup_log(
                        &paths,
                        &format!("Speaker diarization worker unavailable: {error}"),
                    );
                }
            }

            if let Err(error) = bootstrap_laravel(&handle) {
                show_startup_error(&handle, &error);
            } else {
                show_laravel_window(&handle);
            }

            Ok(())
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { .. } = event {
                stop_laravel(&window.app_handle());
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
