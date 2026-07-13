<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CodeQualityToolingConfigurationTest extends TestCase
{
    public function test_php_static_analysis_is_configured_for_application_code(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(file_get_contents($root.'/composer.json'), true);
        $phpstan = file_get_contents($root.'/phpstan.neon');

        $this->assertArrayHasKey('larastan/larastan', $composer['require-dev'] ?? []);
        $this->assertArrayHasKey('phpstan/phpstan', $composer['require-dev'] ?? []);
        $this->assertSame(
            ['.\\php\\php.exe vendor\\bin\\phpstan analyse --memory-limit=1G'],
            $composer['scripts']['analyse'] ?? null,
        );
        $this->assertStringContainsString('vendor/larastan/larastan/extension.neon', $phpstan);
        $this->assertStringContainsString('level: 1', $phpstan);
        $this->assertStringContainsString('- app', $phpstan);
    }
}
