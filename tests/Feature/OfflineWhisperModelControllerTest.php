<?php

namespace Tests\Feature;

use App\Services\OfflineWhisperModelService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OfflineWhisperModelControllerTest extends TestCase
{
    public function test_header_has_a_separate_offline_model_download_button(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-offline-model-download', false)
            ->assertSee('data-offline-model-dialog', false)
            ->assertSee('data-offline-model-minimize', false)
            ->assertSee('data-offline-model-cancel', false)
            ->assertSee('data-offline-model-compact', false)
            ->assertSee('z-[60]', false)
            ->assertSee('z-[70]', false)
            ->assertSee('Download Offline')
            ->assertSee('cursor-pointer', false)
            ->assertSee(route('offline-model.status'), false)
            ->assertSee(route('offline-model.download'), false)
            ->assertDontSee('data-app-update-minimize', false);
    }

    public function test_header_renders_the_engine_switch_when_a_supported_model_is_installed(): void
    {
        $directory = storage_path('framework/testing/offline-whisper-header-'.uniqid());
        $modelPath = $directory.'/ggml-tiny-q8_0.bin';
        File::ensureDirectoryExists($directory);
        File::put($modelPath, 'installed');
        config([
            'services.whisper.model_directory' => $directory,
            'services.whisper.model_min_bytes' => 1,
            'services.whisper.memory_budget_mb' => 600,
        ]);

        try {
            $content = $this->get('/')->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<button[^>]*data-offline-model-download[^>]*hidden[^>]*class="hidden/s',
                $content,
            );
            $this->assertMatchesRegularExpression(
                '/<div[^>]*data-transcription-engine-switch[^>]*class="flex/s',
                $content,
            );
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_status_reports_when_the_model_is_missing(): void
    {
        config([
            'services.whisper.model' => storage_path('framework/testing/missing-whisper.bin'),
            'services.whisper.bundled_model' => storage_path('framework/testing/missing-bundled-whisper.bin'),
        ]);

        $this->getJson('/offline-model/status')
            ->assertOk()
            ->assertJsonPath('installed', false)
            ->assertJsonPath('model', 'large-v3-turbo-q8_0');
    }

    public function test_status_disables_models_above_the_safe_memory_budget(): void
    {
        config([
            'services.whisper.model' => storage_path('framework/testing/missing-whisper.bin'),
            'services.whisper.memory_budget_mb' => 600,
            'services.whisper.threads' => 2,
        ]);

        $this->getJson('/offline-model/status')
            ->assertOk()
            ->assertJsonPath('resource_profile.cpu_threads', 2)
            ->assertJsonPath('resource_profile.memory_budget_mb', 600)
            ->assertJsonPath('models.0.id', 'tiny')
            ->assertJsonPath('models.0.supported', true)
            ->assertJsonPath('models.1.id', 'small')
            ->assertJsonPath('models.1.supported', false);
    }

    public function test_status_lists_the_fine_tuned_cebuano_ct2_model_as_runtime_incompatible(): void
    {
        $status = $this->getJson('/offline-model/status')
            ->assertOk()
            ->json();

        $model = collect($status['models'])
            ->firstWhere('id', 'cebuano-turbo-ct2');

        $this->assertIsArray($model);
        $this->assertSame('Cebuano/Bisaya Turbo', $model['label']);
        $this->assertSame('ctranslate2', $model['runtime']);
        $this->assertFalse($model['supported']);
        $this->assertFalse($model['downloadable']);
        $this->assertSame('https://huggingface.co/arrow2026/whisper-turbo-cebuano-epoch1-ct2/tree/main', $model['source_url']);
        $this->assertStringContainsString('CTranslate2/faster-whisper', $model['unsupported_reason']);
    }

    public function test_fine_tuned_ct2_model_is_not_offered_as_a_whisper_cpp_transcription_choice(): void
    {
        $catalog = app(OfflineWhisperModelService::class)->catalog();

        $this->assertContains('turbo', array_column($catalog, 'id'));
        $this->assertNotContains('cebuano-turbo-ct2', array_column($catalog, 'id'));
    }

    public function test_fine_tuned_ct2_model_download_is_blocked_until_a_compatible_runtime_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requires a CTranslate2/faster-whisper runtime.');

        app(OfflineWhisperModelService::class)->download(
            'cebuano-turbo-ct2',
            static function (): void {},
        );
    }

    public function test_download_streams_and_installs_the_verified_model(): void
    {
        $directory = storage_path('framework/testing/offline-whisper-'.uniqid());
        $modelPath = $directory.'/ggml-large-v3-turbo-q8_0.bin';
        $sourcePath = $directory.'/source.bin';
        $payload = 'verified fake whisper model';
        File::ensureDirectoryExists($directory);
        File::put($sourcePath, $payload);
        config([
            'services.whisper.model' => $modelPath,
            'services.whisper.bundled_model' => $directory.'/missing-bundled.bin',
            'services.whisper.model_url' => $this->fileUrl($sourcePath),
            'services.whisper.fallback_model_url' => '',
            'services.whisper.model_sha1' => sha1($payload),
            'services.whisper.model_min_bytes' => 1,
        ]);
        try {
            $response = $this->post('/offline-model/download');

            $response
                ->assertOk()
                ->assertHeader('X-Offline-Model-Download', 'true');
            $this->assertStringContainsString('"type":"complete"', $response->streamedContent());
            $this->assertSame($payload, File::get($modelPath));
            $this->getJson('/offline-model/status')
                ->assertOk()
                ->assertJsonPath('installed', true);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_connection_failure_is_logged_but_not_exposed(): void
    {
        $missingSource = storage_path('framework/testing/missing-model-source-'.uniqid().'.bin');
        config([
            'services.whisper.model' => storage_path('framework/testing/missing-download-whisper.bin'),
            'services.whisper.bundled_model' => storage_path('framework/testing/missing-bundled-whisper.bin'),
            'services.whisper.model_url' => $this->fileUrl($missingSource),
            'services.whisper.fallback_model_url' => '',
        ]);
        Log::spy();

        $response = $this->post('/offline-model/download');
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString('"type":"error"', $content);
        $this->assertStringContainsString('Offline model download is unavailable right now.', $content);
        $this->assertStringNotContainsString($missingSource, $content);

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Offline Whisper model cURL download failed.'
                && $context['ca_bundle_exists'] === true
                && $context['curl_error'] !== ''
        )->once();
    }

    public function test_download_falls_back_when_the_primary_source_refuses_the_connection(): void
    {
        $directory = storage_path('framework/testing/offline-whisper-fallback-'.uniqid());
        $modelPath = $directory.'/ggml-large-v3-turbo-q8_0.bin';
        $fallbackSource = $directory.'/fallback-source.bin';
        $missingPrimary = $directory.'/missing-primary.bin';
        $payload = 'verified model from fallback';
        File::ensureDirectoryExists($directory);
        File::put($fallbackSource, $payload);
        config([
            'services.whisper.model' => $modelPath,
            'services.whisper.bundled_model' => $directory.'/missing-bundled.bin',
            'services.whisper.model_url' => $this->fileUrl($missingPrimary),
            'services.whisper.fallback_model_url' => $this->fileUrl($fallbackSource),
            'services.whisper.model_sha1' => sha1($payload),
            'services.whisper.model_min_bytes' => 1,
        ]);
        try {
            $response = $this->post('/offline-model/download');

            $response->assertOk();
            $content = $response->streamedContent();
            $this->assertStringContainsString('"type":"complete"', $content);
            $this->assertSame($payload, File::get($modelPath));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_cancelled_download_removes_the_partial_model(): void
    {
        $directory = storage_path('framework/testing/offline-whisper-cancel-'.uniqid());
        $modelPath = $directory.'/ggml-large-v3-turbo-q8_0.bin';
        $sourcePath = $directory.'/source.bin';
        File::ensureDirectoryExists($directory);
        File::put($sourcePath, str_repeat('cancel-me', 4096));
        config([
            'services.whisper.model' => $modelPath,
            'services.whisper.model_url' => $this->fileUrl($sourcePath),
            'services.whisper.fallback_model_url' => '',
            'services.whisper.model_min_bytes' => 1,
        ]);

        try {
            app(OfflineWhisperModelService::class)->download(
                OfflineWhisperModelService::DEFAULT_MODEL,
                static function (): void {},
                static fn (): bool => true,
            );

            $this->fail('The canceled download should throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('canceled', $exception->getMessage());
            $this->assertFileDoesNotExist($modelPath.'.download');
            $this->assertFileDoesNotExist($modelPath);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function fileUrl(string $path): string
    {
        return 'file:///'.ltrim(str_replace('\\', '/', $path), '/');
    }
}
