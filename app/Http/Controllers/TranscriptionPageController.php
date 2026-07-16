<?php

namespace App\Http\Controllers;

use App\Services\Config\AppSettingsService;
use App\Services\Speech\OfflineWhisperModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

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

    public function desktopLoading(): View
    {
        return view('pages.desktop-loading');
    }

    public function desktopAssetsReady(): JsonResponse
    {
        if (! config('app.desktop_dev')) {
            return response()->json(['ready' => true]);
        }

        try {
            $vite = Http::timeout(2)
                ->connectTimeout(1)
                ->get('http://127.0.0.1:5173/@vite/client');
        } catch (Throwable) {
            return response()->json(['ready' => false], 503);
        }

        return $vite->ok()
            ? response()->json(['ready' => true])
            : response()->json(['ready' => false], 503);
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
