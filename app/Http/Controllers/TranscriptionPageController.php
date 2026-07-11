<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use App\Services\OfflineWhisperModelService;
use Illuminate\View\View;

class TranscriptionPageController extends Controller
{
    public function live(AppSettingsService $settings, OfflineWhisperModelService $offlineModels): View
    {
        return view('welcome', $this->transcriptionControls($settings, $offlineModels));
    }

    public function upload(AppSettingsService $settings, OfflineWhisperModelService $offlineModels): View
    {
        return view('pages.upload', $this->transcriptionControls($settings, $offlineModels));
    }

    /**
     * @return array<string, mixed>
     */
    private function transcriptionControls(AppSettingsService $settings, OfflineWhisperModelService $offlineModels): array
    {
        return [
            'languageOptions' => $settings->speechToTextLanguageOptions(),
            'whisperModels' => $offlineModels->catalog(),
        ];
    }
}
