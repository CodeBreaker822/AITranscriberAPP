<?php

namespace App\Providers;

use App\Services\Audio\SpeechActivityDetector;
use App\Services\Audio\SpeechActivityDetectorResolver;
use App\Services\Config\AppSettingsService;
use App\Services\Speech\OfflineWhisperModelService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            SpeechActivityDetector::class,
            fn ($app): SpeechActivityDetector => $app->make(SpeechActivityDetectorResolver::class)->detector(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('components.app-layout', function ($view): void {
            $settings = app(AppSettingsService::class);
            $offlineModels = app(OfflineWhisperModelService::class);

            $view->with([
                'resourceProfile' => $settings->resourceProfile(),
                'hasOfflineTranscriptionModel' => $offlineModels->hasSupportedInstalledModel(),
                'speechProvider' => $settings->speechToTextProvider(),
                'audioChunkSeconds' => $settings->audioChunkSeconds(),
                'transcribeMaxBatchDurationMs' => $settings->transcribeMaxBatchDurationMs() ?? 1_200_000,
                'transcribeMaxBatchClips' => $settings->transcribeMaxBatchClips() ?? 20,
            ]);
        });

        View::composer('pages.upload', function ($view): void {
            $view->with('audioChunkSeconds', app(AppSettingsService::class)->audioChunkSeconds());
        });

        View::composer('components.app-header', function ($view): void {
            $view->with('navItems', [
                [
                    'key' => 'live',
                    'label' => 'Live',
                    'href' => route('transcription.live'),
                    'icon' => 'mic',
                ],
                [
                    'key' => 'upload',
                    'label' => 'Upload',
                    'href' => route('transcription.upload'),
                    'icon' => 'upload',
                ],
            ]);
        });
    }
}
