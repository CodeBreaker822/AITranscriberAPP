<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\Audio\AudioFileChunkerService;
use App\Services\Config\AppSettingsService;
use App\Services\Support\ServiceUserMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class UploadedAudioTranscriptionController extends Controller
{
    public function store(
        Request $request,
        AudioFileChunkerService $chunker,
        AppSettingsService $settings,
    ): JsonResponse {
        @set_time_limit(0);

        $audioChunkSeconds = $settings->audioChunkSeconds();

        $validated = $request->validate([
            'audio_file' => ['nullable', 'required_without:local_path', 'file'],
            'local_path' => ['nullable', 'required_without:audio_file', 'string'],
            'chunk_seconds' => ['nullable', 'integer', Rule::in([$audioChunkSeconds])],
            'duration_ms' => ['nullable', 'integer', 'min:1'],
        ]);

        $file = $request->file('audio_file');
        $localPath = (string) ($validated['local_path'] ?? '');
        $chunkSeconds = (int) ($validated['chunk_seconds'] ?? $audioChunkSeconds);
        $durationMs = (int) ($validated['duration_ms'] ?? 0);

        try {
            $session = trim($localPath) !== ''
                ? $chunker->createSessionFromPath($localPath, $durationMs)
                : $chunker->createSession($file);
            $sections = $chunker->buildSections($session['duration_ms'], $chunkSeconds);

            return response()->json([
                'message' => 'ready',
                'data' => [
                    'session_id' => $session['session_id'],
                    'duration_ms' => $session['duration_ms'],
                    'sections' => $sections,
                    'count' => count($sections),
                ],
            ], 201);
        } catch (SpeechToTextException $exception) {
            Log::error('Audio upload preparation failed during transcription setup.', [
                'message' => $exception->getMessage(),
                'entry' => trim($localPath) !== '' ? 'local_path' : 'audio_file',
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Audio upload could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'entry' => trim($localPath) !== '' ? 'local_path' : 'audio_file',
                'local_path_exists' => trim($localPath) !== '' ? is_file($localPath) : null,
            ]);

            return response()->json([
                'message' => ServiceUserMessage::audioPrepareFailed(),
            ], 500);
        }
    }
}
