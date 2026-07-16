<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DesktopDevStartupConfigurationTest extends TestCase
{
    public function test_global_and_bundled_development_commands_are_separate(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode(file_get_contents($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('php artisan serve', $composer['scripts']['dev'][1]);
        $this->assertStringContainsString('npm run dev', $composer['scripts']['dev'][1]);
        $this->assertSame('vite', $package['scripts']['dev']);
        $this->assertArrayHasKey('dev:local', $composer['scripts']);
        $this->assertSame('node scripts/dev-local.mjs', $package['scripts']['dev:local']);
        $this->assertStringContainsString('.\\php\\php.exe', $composer['scripts']['dev:local'][1]);
    }

    public function test_laravel_dev_server_is_not_blocked_by_vite_readiness(): void
    {
        $root = dirname(__DIR__, 2);
        $vite = file_get_contents($root.'/vite.config.js');
        $launcher = file_get_contents($root.'/scripts/dev-local.mjs');
        $tauri = json_decode(file_get_contents($root.'/src-tauri/tauri.conf.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString("host: '127.0.0.1'", $vite);
        $this->assertStringContainsString("port: 5173", $vite);
        $this->assertStringContainsString("'**/.git/**'", $vite);
        $this->assertStringContainsString("'**/.git-broken/**'", $vite);
        $this->assertStringNotContainsString('waitForVite', $launcher);
        $this->assertStringNotContainsString('@vite/client', $launcher);
        $this->assertLessThan(
            strpos($launcher, "start('Laravel server'"),
            strpos($launcher, "start('Vite server'"),
        );
        $this->assertSame('.\\node\\node.exe scripts\\dev-local.mjs', $tauri['build']['beforeDevCommand']);
        $this->assertSame('.\\node\\node.exe scripts\\prepare-desktop.mjs', $tauri['build']['beforeBuildCommand']);
        $this->assertSame('http://127.0.0.1:8010/desktop-loading', $tauri['build']['devUrl']);
        $this->assertSame('#071018', $tauri['app']['windows'][0]['backgroundColor']);
    }

    public function test_windows_build_treats_the_vulkan_loader_as_optional(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/prepare-desktop.mjs');

        $this->assertStringContainsString('process.env.VULKAN_SDK', $script);
        $this->assertStringContainsString("'System32', 'vulkan-1.dll'", $script);
        $this->assertStringContainsString('return;', $script);
        $this->assertStringContainsString('copyFileSync(source, destination)', $script);
    }

    public function test_release_builder_makes_vulkan_an_explicit_opt_in_feature(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/build-desktop.mjs');

        $this->assertStringContainsString('const vulkanBuild', $script);
        $this->assertStringContainsString('vulkanBuild && !vulkanSdk', $script);
        $this->assertStringContainsString("args.push('--features', 'vulkan')", $script);
        $this->assertStringContainsString('env: vulkanSdk ? { ...process.env, VULKAN_SDK: vulkanSdk } : process.env', $script);
        $this->assertStringContainsString('KhronosGroup.VulkanSDK', $script);
    }
    public function test_php_launchers_base_memory_budget_on_physical_ram_not_current_free_ram(): void
    {
        $root = dirname(__DIR__, 2);
        $devLauncher = file_get_contents($root.'/scripts/dev-local.mjs');
        $phpLauncher = file_get_contents($root.'/scripts/run-php.mjs');
        $profile = file_get_contents($root.'/scripts/resource-profile.mjs');

        foreach ([$devLauncher, $phpLauncher] as $launcher) {
            $this->assertStringContainsString('resourceEnvironment()', $launcher);
        }

        $this->assertStringContainsString('Math.floor(totalMemoryMb / 2)', $profile);
        $this->assertStringContainsString('AI_TRANSCRIBER_TOTAL_MEMORY_MB', $profile);
        $this->assertStringContainsString('AI_TRANSCRIBER_AVAILABLE_MEMORY_MB', $profile);
        $this->assertStringContainsString('AI_TRANSCRIBER_GPU_VRAM_MB', $profile);
        $this->assertStringNotContainsString('availableMemoryMb * 2', $profile);
    }

    public function test_desktop_startup_clears_stale_queue_jobs_before_starting_worker(): void
    {
        $root = dirname(__DIR__, 2);
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $devLauncher = file_get_contents($root.'/scripts/dev-local.mjs');

        $this->assertLessThan(strpos($main, '.arg("queue:work")'), strpos($main, 'clear_pending_queue('));
        $this->assertStringContainsString('"Pending queue jobs cleared before worker startup."', $main);
        $this->assertStringContainsString('["default", "audio", "transcripts"]', $main);
        $this->assertStringContainsString('["audio", "transcripts", "default"]', $main);
        foreach (['default', 'audio', 'transcripts'] as $queue) {
            $this->assertStringContainsString("'--queue={$queue}'", $devLauncher);
        }
        $this->assertLessThan(strpos($devLauncher, "start('Audio queue worker'"), strpos($devLauncher, "'--queue=audio'"));
        $this->assertLessThan(strpos($devLauncher, "start('Transcript queue worker'"), strpos($devLauncher, "'--queue=transcripts'"));
        $this->assertLessThan(strpos($devLauncher, "start('Default queue worker'"), strpos($devLauncher, "'--queue=default'"));
    }
}
