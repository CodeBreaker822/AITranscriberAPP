<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OfflineWhisperConfigurationTest extends TestCase
{
    public function test_auto_language_runs_transcription_instead_of_detection_only_mode(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/offline_whisper.rs');

        $this->assertStringContainsString('params.set_language(None)', $source);
        $this->assertStringNotContainsString('params.set_detect_language(true)', $source);
    }

    public function test_empty_offline_output_is_returned_for_the_controller_to_treat_as_no_speech(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/Speech/OfflineWhisperPayloadNormalizer.php');

        $this->assertStringNotContainsString("if (\$text === '')", $source);
        $this->assertStringNotContainsString('returned no transcript', $source);
        $this->assertStringContainsString("'text' => \$text", $source);
    }

    public function test_invalid_whisper_bytes_are_replaced_instead_of_failing_the_chunk(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/offline_whisper.rs');

        $this->assertStringContainsString('.to_str_lossy()', $source);
        $this->assertStringNotContainsString("segment\n                .to_str()", $source);
    }

    public function test_tauri_worker_keeps_one_model_loaded_across_chunk_requests(): void
    {
        $root = dirname(__DIR__, 2);
        $engine = file_get_contents($root.'/src-tauri/src/offline_whisper.rs');
        $worker = file_get_contents($root.'/src-tauri/src/offline_whisper_worker.rs');
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $service = file_get_contents($root.'/app/Services/Speech/OfflineWhisperService.php');
        $workerClient = file_get_contents($root.'/app/Services/Speech/OfflineWhisperWorkerClient.php');

        $this->assertStringContainsString('pub struct OfflineWhisperEngine', $engine);
        $this->assertStringContainsString('loaded.uses_configuration(&model_path, use_gpu)', $worker);
        $this->assertStringContainsString('let mut engine: Option<OfflineWhisperEngine> = None', $worker);
        $this->assertStringContainsString('IDLE_MODEL_TIMEOUT', $worker);
        $this->assertStringContainsString('offline_whisper_worker::start', $main);
        $this->assertStringContainsString('offline-whisper-worker.json', $workerClient);
        $this->assertStringContainsString("'release' => (bool) (\$options['release_worker'] ?? false)", $service);
    }

    public function test_offline_whisper_progress_flows_from_native_worker_to_the_ui(): void
    {
        $root = dirname(__DIR__, 2);
        $engine = file_get_contents($root.'/src-tauri/src/offline_whisper.rs');
        $worker = file_get_contents($root.'/src-tauri/src/offline_whisper_worker.rs');
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $service = file_get_contents($root.'/app/Services/Speech/OfflineWhisperService.php');
        $frontend = file_get_contents($root.'/resources/js/app.js');
        $upload = file_get_contents($root.'/resources/js/upload/upload-controller.js');
        $live = file_get_contents($root.'/resources/js/live/live-controller.js');

        $this->assertStringContainsString('set_progress_callback_safe', $engine);
        $this->assertStringContainsString('progress_id: Option<String>', $worker);
        $this->assertStringContainsString('offline-whisper-progress', $main);
        $this->assertStringContainsString("'progress_id'", $service);
        $this->assertStringContainsString("tauriEventListen('offline-whisper-progress'", $frontend);
        $this->assertStringContainsString('Whisper ${Math.round(whisperPercent)}%', $upload);
        $this->assertStringContainsString('Whisper ${Math.round(whisperPercent)}%', $live);
    }

    public function test_offline_whisper_can_be_cancelled_while_inference_is_running(): void
    {
        $root = dirname(__DIR__, 2);
        $engine = file_get_contents($root.'/src-tauri/src/offline_whisper.rs');
        $worker = file_get_contents($root.'/src-tauri/src/offline_whisper_worker.rs');
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $build = file_get_contents($root.'/src-tauri/build.rs');
        $frontend = file_get_contents($root.'/resources/js/app.js');
        $upload = file_get_contents($root.'/resources/js/upload/upload-controller.js');
        $live = file_get_contents($root.'/resources/js/live/live-controller.js');

        $this->assertStringContainsString('set_abort_callback_safe', $engine);
        $this->assertStringContainsString('pub fn cancel(progress_id: &str)', $worker);
        $this->assertStringContainsString('AtomicBool', $worker);
        $this->assertStringContainsString('fn cancel_offline_whisper(progress_id: String)', $main);
        $this->assertStringContainsString('"cancel_offline_whisper"', $build);
        $this->assertStringContainsString("invoke('cancel_offline_whisper'", $frontend);
        $this->assertStringContainsString('cancelWhisperProgress(uploadState.activeSectionProgressId)', $upload);
        $this->assertStringContainsString('cancelWhisperProgress(liveState.activeWhisperProgressId)', $live);
    }

    public function test_offline_worker_retries_transport_failures_and_recovers_from_panics(): void
    {
        $root = dirname(__DIR__, 2);
        $workerClient = file_get_contents($root.'/app/Services/Speech/OfflineWhisperWorkerClient.php');
        $worker = file_get_contents($root.'/src-tauri/src/offline_whisper_worker.rs');

        $this->assertStringContainsString('MAX_WORKER_RETRIES = 3', $workerClient);
        $this->assertStringContainsString('MAX_WORKER_RETRIES + 1', $workerClient);
        $this->assertStringContainsString('response_prefix_hex', $workerClient);
        $this->assertStringContainsString("\$payload['retryable']", $workerClient);
        $this->assertStringContainsString('catch_unwind', $worker);
        $this->assertStringContainsString('worker recovered from an internal failure', $worker);
        $this->assertStringContainsString('"retryable": true', $worker);
    }

    public function test_desktop_profiles_resources_once_and_passes_a_bounded_thread_budget(): void
    {
        $root = dirname(__DIR__, 2);
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $whisper = file_get_contents($root.'/src-tauri/src/offline_whisper.rs');
        $worker = file_get_contents($root.'/src-tauri/src/offline_whisper_worker.rs');
        $service = file_get_contents($root.'/app/Services/Speech/OfflineWhisperService.php');

        $this->assertStringContainsString('struct ResourceProfile', $main);
        $this->assertStringContainsString('AI_TRANSCRIBER_WHISPER_THREADS', $main);
        $this->assertStringContainsString('AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB', $main);
        $this->assertStringContainsString('AI_TRANSCRIBER_WHISPER_GPU_VRAM_BUDGET_MB', $main);
        $this->assertStringContainsString('detect_whisper_gpu()', $main);
        $this->assertStringContainsString('whisper_memory_budget(total_memory_mb)', $main);
        $this->assertStringNotContainsString('available_memory_mb.saturating_mul(2)', $main);
        $this->assertStringContainsString('BELOW_NORMAL_PRIORITY_CLASS', $main);
        $this->assertStringContainsString('thread_budget.max(1)', $whisper);
        $this->assertStringNotContainsString('available_parallelism', $whisper);
        $this->assertStringContainsString('logical_processors * 3 / 5', $main);
        $this->assertStringNotContainsString('clamp(1, 6)', $main.$whisper);
        $this->assertStringContainsString("'--threads'", $service);
        $this->assertStringContainsString("\$useGpu ? '--gpu' : '--cpu'", $service);
        $this->assertStringContainsString('parameters.use_gpu(use_gpu)', $whisper);
        $this->assertStringContainsString('#[cfg(feature = "vulkan")]', $main);
        $this->assertStringContainsString('whisper_rs::vulkan::list_devices', $main);
        $this->assertStringContainsString('#[cfg(not(feature = "vulkan"))]', $main);
        $this->assertStringContainsString('OfflineWhisperEngine::load_cpu_fallback', $worker);
        $this->assertStringContainsString('OfflineWhisperEngine::gpu_enabled', $worker);
        $this->assertStringContainsString('Choose a smaller model', $service);
    }
}
