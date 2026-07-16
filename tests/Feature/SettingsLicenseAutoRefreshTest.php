<?php

namespace Tests\Feature;

use App\Services\Config\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsLicenseAutoRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_auto_loads_provider_and_model_when_license_is_saved(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setApiBaseUrl('https://dilgaims.site/api');
        $settings->setLicenseKey('saved-license-key');

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'expired' => false,
                'apis' => [
                    'transcribe' => ['allowed' => true],
                ],
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
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->get('/settings');

        $response
            ->assertOk()
            ->assertSee('Ready')
            ->assertSee('Saved license ending in e-key')
            ->assertDontSee('value="saved-license-key"', false)
            ->assertSee('Deepgram')
            ->assertSee('Nova-3')
            ->assertDontSee('Needs server check');

        $this->assertSame('deepgram', $settings->speechToTextProvider());
        $this->assertSame('nova-3', $settings->speechToTextModel('deepgram'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://dilgaims.site/api/license/status'
                && $request->hasHeader('Authorization', 'Bearer saved-license-key');
        });
    }

    public function test_blank_license_input_keeps_saved_license_key(): void
    {
        config([
            'services.whisper.threads' => 4,
            'services.whisper.memory_budget_mb' => 4096,
            'services.resources.logical_processors' => 8,
            'services.resources.total_memory_mb' => 8192,
            'services.resources.gpu_available' => true,
            'services.resources.gpu_name' => 'Test Vulkan GPU',
            'services.resources.gpu_vram_mb' => 4096,
            'services.whisper.gpu_vram_budget_mb' => 3072,
        ]);

        $settings = app(AppSettingsService::class);
        $settings->setApiBaseUrl('https://dilgaims.site/api');
        $settings->setLicenseKey('saved-license-key');

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'expired' => false,
                'apis' => [
                    'transcribe' => ['allowed' => true],
                ],
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
                                    'languages' => [
                                        ['code' => 'multi', 'label' => 'Multilingual'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->post('/settings', [
            'api_base_url' => 'https://dilgaims.site/api',
            'license_key' => '',
            'speech_to_text_provider' => 'deepgram',
            'speech_to_text_model' => 'nova-3',
            'resource_mode' => 'manual',
            'resource_cpu_threads' => 4,
            'resource_memory_budget_mb' => 2048,
            'resource_gpu_vram_budget_mb' => 2048,
        ]);

        $response->assertRedirect('/settings');

        $this->assertSame('saved-license-key', $settings->licenseKey());
        $this->assertSame('manual', $settings->resourceProfile()['mode']);
        $this->assertSame(4, $settings->resourceProfile()['cpu_threads']);
        $this->assertSame(2048, $settings->resourceProfile()['memory_budget_mb']);
        $this->assertSame(2048, $settings->resourceProfile()['gpu_vram_budget_mb']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://dilgaims.site/api/license/status'
                && $request->hasHeader('Authorization', 'Bearer saved-license-key');
        });
    }

    public function test_manual_resource_settings_cannot_exceed_detected_hardware(): void
    {
        config([
            'services.whisper.threads' => 4,
            'services.whisper.memory_budget_mb' => 4096,
            'services.resources.logical_processors' => 8,
            'services.resources.total_memory_mb' => 8192,
        ]);

        $settings = app(AppSettingsService::class);
        $settings->setApiBaseUrl('https://dilgaims.site/api');
        $settings->setLicenseKey('saved-license-key');

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'expired' => false,
                'apis' => [
                    'transcribe' => ['allowed' => true],
                ],
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
                                    'languages' => [
                                        ['code' => 'multi', 'label' => 'Multilingual'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->from('/settings')->post('/settings', [
            'api_base_url' => 'https://dilgaims.site/api',
            'license_key' => '',
            'speech_to_text_provider' => 'deepgram',
            'speech_to_text_model' => 'nova-3',
            'resource_mode' => 'manual',
            'resource_cpu_threads' => 9,
            'resource_memory_budget_mb' => 8193,
            'resource_gpu_vram_budget_mb' => 4097,
        ]);

        $response
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['resource_cpu_threads', 'resource_memory_budget_mb', 'resource_gpu_vram_budget_mb']);
    }
}
