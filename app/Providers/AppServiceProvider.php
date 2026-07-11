<?php

namespace App\Providers;

use App\Services\AppSettingsService;
use App\Services\OfflineWhisperModelService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
                'transcribeMaxBatchDurationMs' => $settings->transcribeMaxBatchDurationMs() ?? 1_200_000,
                'transcribeMaxBatchClips' => $settings->transcribeMaxBatchClips() ?? 20,
            ]);
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
