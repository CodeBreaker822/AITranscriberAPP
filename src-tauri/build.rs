fn main() {
    let manifest_dir = std::path::PathBuf::from(
        std::env::var("CARGO_MANIFEST_DIR").expect("missing Cargo manifest directory"),
    );
    let config_path = manifest_dir.join("tauri.conf.json");
    let config: serde_json::Value = serde_json::from_str(
        &std::fs::read_to_string(&config_path).expect("failed to read Tauri configuration"),
    )
    .expect("failed to parse Tauri configuration");
    let version = config
        .get("version")
        .and_then(serde_json::Value::as_str)
        .expect("Tauri configuration is missing its version");
    let version_path = manifest_dir.join("../build/tauri/version.json");
    std::fs::create_dir_all(
        version_path
            .parent()
            .expect("invalid version metadata path"),
    )
    .expect("failed to create version metadata directory");
    std::fs::write(
        version_path,
        format!(
            "{{\n  \"version\": {},\n  \"notes\": {}\n}}\n",
            serde_json::to_string(version).expect("failed to encode version"),
            serde_json::to_string(&format!("AITranscriber {version} update."))
                .expect("failed to encode default release notes"),
        ),
    )
    .expect("failed to write version metadata");

    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&[
            "open_external_url",
            "save_text_export",
            "save_text_export_with_dialog",
            "save_transcript_export_with_dialog",
            "choose_audio_file",
            "cancel_offline_whisper",
            "install_update",
        ]),
    ))
    .expect("failed to build Tauri application");
}
