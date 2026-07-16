<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\Updates\AppUpdateService;
use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Speech\OfflineWhisperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AppUpdateController extends Controller
{
    public function connectivity(HostedTranscriptionApiService $api, OfflineWhisperService $offlineWhisper): JsonResponse
    {
        // The PHP development server handles one request at a time. Avoid blocking
        // every local page load on an external network probe while Vite is active.
        $online = config('app.desktop_dev') || $api->serverIsReachable();

        return response()->json([
            'online' => $online,
            'offline_available' => $offlineWhisper->isAvailable(),
            'offline_model_available' => $offlineWhisper->modelIsAvailable(),
        ]);
    }

    public function status(AppUpdateService $updates): JsonResponse
    {
        try {
            return response()->json($updates->status());
        } catch (SpeechToTextException $exception) {
            return response()->json([
                'available' => false,
            ]);
        }
    }

    public function download(Request $request, AppUpdateService $updates): StreamedResponse|JsonResponse
    {
        try {
            $remote = $updates->download($request->query('url'));
        } catch (SpeechToTextException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        if ($remote->failed()) {
            return response()->json([
                'message' => $remote->json('message') ?: 'The update ZIP could not be downloaded.',
            ], $remote->status());
        }

        $updates->prepareArchiveDirectory();
        $archivePath = $updates->archivePath();
        $partialPath = $archivePath.'.part';
        $contentLength = (int) ($remote->header('Content-Length') ?? 0);
        $headers = [
            'Content-Type' => $remote->header('Content-Type') ?: 'application/zip',
            'Cache-Control' => 'no-store',
            'X-Update-Archive-Path' => $archivePath,
        ];

        if ($contentLength > 0) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        return response()->stream(function () use ($remote, $partialPath, $archivePath): void {
            $source = $remote->toPsrResponse()->getBody();
            $destination = fopen($partialPath, 'wb');

            if ($destination === false) {
                throw new RuntimeException('The update ZIP could not be saved locally.');
            }

            try {
                while (! $source->eof()) {
                    $chunk = $source->read(1024 * 256);

                    if ($chunk === '') {
                        continue;
                    }

                    if (fwrite($destination, $chunk) === false) {
                        throw new RuntimeException('The update ZIP could not be saved locally.');
                    }

                    echo $chunk;

                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }

                    flush();
                }
            } catch (Throwable $exception) {
                @unlink($partialPath);
                throw $exception;
            } finally {
                fclose($destination);
            }

            @unlink($archivePath);

            if (! rename($partialPath, $archivePath)) {
                @unlink($partialPath);
                throw new RuntimeException('The downloaded update ZIP could not be finalized.');
            }
        }, 200, $headers);
    }
}
