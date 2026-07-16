<?php

namespace Tests\Unit;

use App\Services\Config\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_editable_api_base_url_with_https_default(): void
    {
        $settings = app(AppSettingsService::class);

        $this->assertSame('https://dilgaims.site/api', $settings->apiBaseUrl());

        $settings->setApiBaseUrl('new-domain.example/api/');

        $this->assertSame('https://new-domain.example/api', $settings->apiBaseUrl());
    }

    public function test_it_returns_only_the_license_key_suffix_for_display(): void
    {
        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('is_license_1234567890');

        $this->assertSame('67890', $settings->licenseKeySuffix());
    }

    public function test_it_uses_server_returned_provider_model_and_language_options(): void
    {
        $settings = app(AppSettingsService::class);

        $settings->setLicenseStatus([
            'providers' => [
                'transcription' => [
                    [
                        'provider' => 'deepgram',
                        'name' => 'Deepgram',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            [
                                'id' => 'nova-3',
                                'label' => 'Nova-3',
                                'default_language_code' => 'multi',
                                'languages' => [
                                    ['code' => 'multi', 'label' => 'Multilingual'],
                                    ['code' => 'en', 'label' => 'English'],
                                    ['code' => 'tl', 'label' => 'Tagalog'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'provider' => 'speechmatics',
                        'name' => 'Speechmatics',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => false,
                        'models' => [
                            ['id' => 'melia-1', 'label' => 'Melia-1', 'languages' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('deepgram', $settings->transcriptionProviderOptions());
        $this->assertArrayNotHasKey('speechmatics', $settings->transcriptionProviderOptions());
        $this->assertSame('nova-3', $settings->speechToTextModel('deepgram'));
        $this->assertSame([
            ['value' => 'multi', 'label' => 'Multilingual'],
            ['value' => 'en', 'label' => 'English'],
            ['value' => 'tl', 'label' => 'Tagalog'],
        ], $settings->speechToTextLanguageOptions('deepgram', 'nova-3'));
    }

    public function test_it_falls_back_to_model_default_language_when_selected_language_is_invalid(): void
    {
        $settings = app(AppSettingsService::class);

        $settings->setLicenseStatus([
            'providers' => [
                'transcription' => [
                    [
                        'provider' => 'speechmatics',
                        'name' => 'Speechmatics',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            [
                                'id' => 'enhanced',
                                'label' => 'Enhanced',
                                'default_language_code' => 'auto',
                                'languages' => [
                                    ['code' => 'auto', 'label' => 'Auto Detect'],
                                    ['code' => 'en', 'label' => 'English'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $settings->setSpeechToTextProvider('speechmatics');
        $settings->setSpeechToTextModel('enhanced');

        $this->assertSame([
            'provider' => 'speechmatics',
            'model' => 'enhanced',
            'language' => 'auto',
        ], $settings->transcriptionSelection('not-returned-by-server'));
    }

    public function test_transcribe_upload_byte_limit_prefers_server_status_before_config_fallback(): void
    {
        config(['services.transcription_api.max_upload_bytes' => 2048]);
        $settings = app(AppSettingsService::class);

        $this->assertSame(2048, $settings->transcribeMaxUploadBytes());

        $settings->setLicenseStatus([
            'apis' => [
                'transcribe' => [
                    'max_batch_bytes' => 1024,
                ],
            ],
        ]);

        $this->assertSame(1024, $settings->transcribeMaxUploadBytes());
    }

    public function test_it_supports_manual_resource_profile_overrides(): void
    {
        config([
            'services.whisper.threads' => 6,
            'services.whisper.memory_budget_mb' => 4096,
            'services.resources.logical_processors' => 12,
            'services.resources.total_memory_mb' => 8192,
            'services.resources.available_memory_mb' => 6144,
            'services.resources.gpu_available' => false,
        ]);

        $settings = app(AppSettingsService::class);

        $this->assertSame([
            'mode' => 'auto',
            'cpu_threads' => 6,
            'memory_budget_mb' => 4096,
            'gpu_available' => false,
            'gpu_name' => '',
            'gpu_vram_mb' => 0,
            'gpu_vram_budget_mb' => 0,
            'auto_cpu_threads' => 6,
            'auto_memory_budget_mb' => 4096,
            'auto_gpu_vram_budget_mb' => 0,
            'max_cpu_threads' => 12,
            'max_memory_budget_mb' => 8192,
            'max_gpu_vram_budget_mb' => 0,
            'total_memory_mb' => 8192,
            'available_memory_mb' => 6144,
        ], $settings->resourceProfile());

        $settings->setResourceProfile('manual', 3, 2048);

        $this->assertSame([
            'mode' => 'manual',
            'cpu_threads' => 3,
            'memory_budget_mb' => 2048,
            'gpu_available' => false,
            'gpu_name' => '',
            'gpu_vram_mb' => 0,
            'gpu_vram_budget_mb' => 0,
            'auto_cpu_threads' => 6,
            'auto_memory_budget_mb' => 4096,
            'auto_gpu_vram_budget_mb' => 0,
            'max_cpu_threads' => 12,
            'max_memory_budget_mb' => 8192,
            'max_gpu_vram_budget_mb' => 0,
            'total_memory_mb' => 8192,
            'available_memory_mb' => 6144,
        ], $settings->resourceProfile());
    }

    public function test_gpu_vram_budget_is_available_only_for_a_compatible_detected_gpu(): void
    {
        config([
            'services.whisper.threads' => 6,
            'services.whisper.memory_budget_mb' => 4096,
            'services.whisper.gpu_vram_budget_mb' => 6144,
            'services.resources.logical_processors' => 12,
            'services.resources.total_memory_mb' => 16384,
            'services.resources.gpu_available' => true,
            'services.resources.gpu_name' => 'NVIDIA RTX Test',
            'services.resources.gpu_vram_mb' => 8192,
        ]);

        $settings = app(AppSettingsService::class);
        $settings->setResourceProfile('manual', 4, 4096, 99999);
        $profile = $settings->resourceProfile();

        $this->assertTrue($profile['gpu_available']);
        $this->assertSame('NVIDIA RTX Test', $profile['gpu_name']);
        $this->assertSame(8192, $profile['gpu_vram_mb']);
        $this->assertSame(6144, $profile['auto_gpu_vram_budget_mb']);
        $this->assertSame(8192, $profile['gpu_vram_budget_mb']);
        $this->assertSame(8192, $profile['max_gpu_vram_budget_mb']);
    }

    public function test_manual_resource_profile_is_clamped_to_hardware_limits(): void
    {
        config([
            'services.whisper.threads' => 6,
            'services.whisper.memory_budget_mb' => 4096,
            'services.resources.logical_processors' => 8,
            'services.resources.total_memory_mb' => 16384,
            'services.resources.available_memory_mb' => 12000,
        ]);

        $settings = app(AppSettingsService::class);
        $settings->setResourceProfile('manual', 99, 999999);

        $profile = $settings->resourceProfile();

        $this->assertSame(8, $profile['cpu_threads']);
        $this->assertSame(16384, $profile['memory_budget_mb']);
        $this->assertSame(8, $profile['max_cpu_threads']);
        $this->assertSame(16384, $profile['max_memory_budget_mb']);
    }
}
