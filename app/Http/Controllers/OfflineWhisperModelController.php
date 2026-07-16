<?php

namespace App\Http\Controllers;

use App\Services\Speech\OfflineWhisperModelService;
use App\Services\Speakers\SpeakerDiarizationModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OfflineWhisperModelController extends Controller
{
    public function status(
        OfflineWhisperModelService $models,
        SpeakerDiarizationModelService $diarization,
    ): JsonResponse
    {
        return response()->json($this->combinedStatus($models, $diarization));
    }

    public function download(
        Request $request,
        OfflineWhisperModelService $models,
    ): StreamedResponse|JsonResponse
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $validated = $request->validate([
            'model' => ['nullable', 'string', Rule::in($models->modelIds())],
        ]);
        $model = (string) ($validated['model'] ?? OfflineWhisperModelService::DEFAULT_MODEL);

        if ($models->isInstalled($model)) {
            return response()->json($models->status());
        }

        $headers = [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
            'X-Offline-Model-Download' => 'true',
        ];

        return response()->stream(function () use ($models, $model): void {
            $send = function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_SLASHES)."\n";

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }

                flush();
            };

            try {
                $models->download(
                    $model,
                    $send,
                    static fn (): bool => connection_aborted() !== 0,
                );
            } catch (Throwable $exception) {
                if (connection_aborted() !== 0) {
                    return;
                }

                Log::error('Offline Whisper model installation failed.', [
                    'model' => $model,
                    'url' => $models->modelUrl(),
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
                $send([
                    'type' => 'error',
                    'message' => 'Offline model download is unavailable right now. Please try again.',
                ]);
            }
        }, 200, $headers);
    }

    private function combinedStatus(
        OfflineWhisperModelService $models,
        SpeakerDiarizationModelService $diarization,
    ): array {
        $status = $models->status();
        $status['models'] = array_map(function (array $model): array {
            $model['kind'] = 'whisper';

            return $model;
        }, $status['models'] ?? []);
        $diarizationStatus = $diarization->status();
        $status['models'][] = $diarizationStatus;
        $status['diarization_installed'] = $diarizationStatus['installed'];

        return $status;
    }
}
