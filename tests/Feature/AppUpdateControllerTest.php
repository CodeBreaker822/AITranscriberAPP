<?php

namespace Tests\Feature;

use App\Services\Config\AppSettingsService;
use App\Services\Updates\AppUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppUpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_app_page_contains_the_shared_update_checker(): void
    {
        foreach (['/', '/upload', '/settings'] as $path) {
            $response = $this->get($path);

            $response
                ->assertOk()
                ->assertSee('data-app-update-dialog', false)
                ->assertSee(route('app-update.connectivity'), false)
                ->assertSee(route('app-update.status'), false)
                ->assertSee(route('app-update.download'), false)
                ->assertSee('js/modals/app-update.js', false);
        }
    }

    public function test_connectivity_probe_is_silent_when_server_is_unreachable(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        Http::fake(function (): never {
            throw new \Illuminate\Http\Client\ConnectionException('offline');
        });

        $this->getJson('/app-update/connectivity')
            ->assertOk()
            ->assertJsonPath('online', false)
            ->assertJsonStructure([
                'online',
                'offline_available',
                'offline_model_available',
            ])
            ->assertDontSee('internet', false)
            ->assertDontSee('connection', false);
    }

    public function test_status_reports_an_update_when_server_version_text_differs(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'version' => '99.0.0',
                'notes' => 'A useful update.',
                'download_url' => '/transcribe/update/releases/AITranscriber-update.zip',
            ]),
        ]);

        $response = $this->getJson('/app-update/status');

        $response
            ->assertOk()
            ->assertJson([
                'available' => true,
                'version' => '99.0.0',
                'notes' => 'A useful update.',
                'download_url' => '/transcribe/update/releases/AITranscriber-update.zip',
            ]);
    }

    public function test_status_does_not_update_when_version_text_is_identical(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');
        $currentVersion = app(AppUpdateService::class)->currentVersion();

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'version' => $currentVersion,
                'notes' => 'No update should run.',
            ]),
        ]);

        $this->getJson('/app-update/status')
            ->assertOk()
            ->assertJson([
                'available' => false,
                'current_version' => $currentVersion,
                'version' => $currentVersion,
            ]);
    }

    public function test_update_zip_is_downloaded_with_the_saved_license(): void
    {
        $originalStoragePath = app()->storagePath();
        $testStoragePath = sys_get_temp_dir().'/aitranscriber-update-test-'.uniqid();
        app()->useStoragePath($testStoragePath);
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/transcribe/update/zipfile' => Http::response(
                'fake zip bytes',
                200,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Length' => '14',
                ],
            ),
        ]);

        try {
            $response = $this->get('/app-update/download');

            $response
                ->assertOk()
                ->assertHeader('Content-Type', 'application/zip')
                ->assertHeader('Content-Length', '14')
                ->assertHeader('X-Update-Archive-Path');

            $this->assertSame('fake zip bytes', $response->streamedContent());

            Http::assertSent(function (Request $request): bool {
                return $request->method() === 'GET'
                    && $request->url() === 'https://dilgaims.site/api/transcribe/update/zipfile'
                    && $request->hasHeader('Authorization', 'Bearer license-123');
            });
        } finally {
            app()->useStoragePath($originalStoragePath);
            File::deleteDirectory($testStoragePath);
        }
    }

    public function test_update_download_uses_the_status_provided_file_path(): void
    {
        $originalStoragePath = app()->storagePath();
        $testStoragePath = sys_get_temp_dir().'/aitranscriber-update-test-'.uniqid();
        app()->useStoragePath($testStoragePath);
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/transcribe/update/releases/AITranscriber-update.zip' => Http::response(
                'linked zip bytes',
                200,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Length' => '16',
                ],
            ),
        ]);

        try {
            $response = $this->get('/app-update/download?url='.urlencode('/transcribe/update/releases/AITranscriber-update.zip'));

            $response
                ->assertOk()
                ->assertHeader('X-Update-Archive-Path');

            $this->assertSame('linked zip bytes', $response->streamedContent());

            Http::assertSent(function (Request $request): bool {
                return $request->method() === 'GET'
                    && $request->url() === 'https://dilgaims.site/api/transcribe/update/releases/AITranscriber-update.zip'
                    && $request->hasHeader('Authorization', 'Bearer license-123');
            });
        } finally {
            app()->useStoragePath($originalStoragePath);
            File::deleteDirectory($testStoragePath);
        }
    }
}
