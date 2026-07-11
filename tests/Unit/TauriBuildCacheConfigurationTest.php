<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TauriBuildCacheConfigurationTest extends TestCase
{
    public function test_nsis_reinstall_silently_skips_files_that_remain_locked(): void
    {
        $root = dirname(__DIR__, 2);
        $windowsConfig = file_get_contents($root.'/src-tauri/tauri.windows.conf.json');
        $hooks = file_get_contents($root.'/src-tauri/nsis/installer-hooks.nsh');

        $this->assertStringContainsString('"installerHooks": "nsis/installer-hooks.nsh"', $windowsConfig);
        $this->assertStringContainsString('!macro NSIS_HOOK_PREINSTALL', $hooks);
        $this->assertStringContainsString('SetOverwrite try', $hooks);
    }

    public function test_cargo_profiles_avoid_large_development_artifacts(): void
    {
        $manifest = file_get_contents(dirname(__DIR__, 2).'/src-tauri/Cargo.toml');

        $this->assertStringContainsString('[profile.dev]', $manifest);
        $this->assertStringContainsString('incremental = false', $manifest);
        $this->assertStringContainsString('[profile.dev.package."*"]', $manifest);
        $this->assertStringContainsString('debug = false', $manifest);
        $this->assertStringContainsString('strip = "symbols"', $manifest);
    }

    public function test_release_build_prunes_only_compilation_cache(): void
    {
        $root = dirname(__DIR__, 2);
        $package = json_decode(file_get_contents($root.'/package.json'), true);
        $builder = file_get_contents($root.'/scripts/build-desktop.mjs');
        $cleaner = file_get_contents($root.'/scripts/clean-tauri-cache.mjs');

        $this->assertSame('node scripts/clean-tauri-cache.mjs', $package['scripts']['clean:tauri'] ?? null);
        $this->assertStringContainsString('cleanReleaseCache()', $builder);
        $this->assertStringContainsString('src-tauri/target/release/deps', $cleaner);
        $this->assertStringContainsString('src-tauri/target/debug', $cleaner);
        $this->assertStringNotContainsString("'src-tauri/target/release/bundle'", $cleaner);
        $this->assertStringNotContainsString("'storage'", $cleaner);
    }

    public function test_windows_whisper_build_generates_native_bindings_with_llvm(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2).'/.cargo/config.toml');

        $this->assertStringNotContainsString('WHISPER_DONT_GENERATE_BINDINGS', $config);
        $this->assertStringContainsString('LIBCLANG_PATH', $config);
        $this->assertStringContainsString('C:\\\\Program Files\\\\LLVM\\\\bin', $config);
    }

    public function test_desktop_runtime_and_bundle_are_windows_only(): void
    {
        $root = dirname(__DIR__, 2);
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $config = file_get_contents($root.'/src-tauri/tauri.conf.json');

        $this->assertStringContainsString('compile_error!("AITranscriber desktop builds are supported on Windows only.")', $main);
        $this->assertStringNotContainsString('xdg-open', $main);
        $this->assertStringNotContainsString('target_os = "macos"', $main);
        $this->assertStringNotContainsString('icons/icon.icns', $config);
        $this->assertStringNotContainsString('"android"', $config);
    }

    public function test_sherpa_models_are_verified_and_bundled_in_installers_and_updates(): void
    {
        $root = dirname(__DIR__, 2);
        $preparer = file_get_contents($root.'/scripts/prepare-desktop.mjs');
        $tauri = file_get_contents($root.'/tauri.release.conf.json');
        $updater = file_get_contents($root.'/scripts/create-update-package.mjs');
        $main = file_get_contents($root.'/src-tauri/src/main.rs');

        $this->assertStringContainsString('verifyBundledSherpaModels()', $preparer);
        $this->assertStringContainsString('d582f4b4c6b48205de7e0643c57df0df5615a3c176189be3fc461e9d18827b5d', $preparer);
        $this->assertStringContainsString('ad4a1802485d8b34c722d2a9d04249662f2ece5d28a7a039063ca22f515a789e', $preparer);
        $this->assertStringContainsString('"../sherpa": "sherpa"', $tauri);
        $this->assertStringContainsString("'sherpa'", $updater);
        $this->assertStringContainsString('verifyReleaseSherpaModels()', $updater);
        $this->assertStringContainsString('SHERPA_DIARIZATION_MODEL_DIRECTORY', $main);
    }
}
