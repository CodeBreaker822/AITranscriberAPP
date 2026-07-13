<?php

namespace App\Http\Controllers;

use App\Enums\TranscriptionEngine;
use App\Services\AudioChunk\AudioChunkIngestionResult;
use App\Services\AudioChunk\AudioChunkIngestionService;
use App\Services\AudioChunk\AudioChunkPersistenceService;
use App\Services\AudioChunk\UploadedAudioBatchPreparationService;
use App\Services\AudioChunk\UploadedDiarizationService;
use App\Services\AudioFileChunkerService;
use App\Services\SpeakerDiarizationService;
use App\Services\UploadedAudioSectionPreparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AudioChunkController extends Controller
{
    public function index(AudioChunkPersistenceService $audioChunks): JsonResponse
    {
        return response()->json($audioChunks->listRows());
    }

    public function status(Request $request, AudioChunkPersistenceService $audioChunks): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($audioChunks->statusRows($validated['ids']));
    }

    public function store(Request $request, AudioChunkIngestionService $ingestion): JsonResponse
    {
        if ($request->filled('upload_session_id')) {
            return $this->ingestionResponse(
                $ingestion->storeUploadedSection($request->validate($this->uploadedSectionRules())),
            );
        }

        return $this->ingestionResponse(
            $ingestion->storeLive($request->file('audio'), $request->validate([
                'audio' => ['required', 'file', 'max:51200'],
                'user_id' => ['nullable', 'integer', 'min:1'],
                'category_name' => ['required', 'string', 'max:120'],
                'clip_index' => ['required', 'integer', 'min:1'],
                'clip_start_ms' => ['required', 'integer', 'min:0'],
                'clip_end_ms' => ['required', 'integer', 'min:0'],
                'range_label' => ['required', 'string', 'max:32'],
                'duration_ms' => ['required', 'integer', 'min:1'],
                'language_code' => ['nullable', 'string', 'max:32'],
                'transcription_engine' => ['nullable', 'string', Rule::in(TranscriptionEngine::values())],
                'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
                'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'progress_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'finalize_session' => ['nullable', 'boolean'],
            ])),
        );
    }

    public function prepareUploadedSection(
        Request $request,
        UploadedAudioSectionPreparationService $preparer,
    ): JsonResponse {
        @set_time_limit(0);

        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'clip_index' => ['required', 'integer', 'min:1'],
            'clip_start_ms' => ['required', 'integer', 'min:0'],
            'clip_end_ms' => ['required', 'integer', 'min:0'],
            'range_label' => ['required', 'string', 'max:32'],
            'duration_ms' => ['required', 'integer', 'min:1'],
            'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        try {
            return response()->json([
                'message' => 'prepared',
                'data' => $preparer->prepare($validated),
            ]);
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio section could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function uploadSessionStatus(Request $request, AudioFileChunkerService $chunker): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        return response()->json([
            'available' => $chunker->sessionAvailable($validated['session_id']),
        ]);
    }

    public function prepareUploadedSectionsBatch(
        Request $request,
        UploadedAudioBatchPreparationService $preparer,
    ): JsonResponse {
        @set_time_limit(0);

        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:64'],
            'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.clip_index' => ['required', 'integer', 'min:1'],
            'sections.*.clip_start_ms' => ['required', 'integer', 'min:0'],
            'sections.*.clip_end_ms' => ['required', 'integer', 'min:0'],
            'sections.*.range_label' => ['required', 'string', 'max:32'],
            'sections.*.duration_ms' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return response()->json($preparer->prepare($validated));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function queueUploadedDiarization(
        Request $request,
        UploadedDiarizationService $diarization,
    ): JsonResponse {
        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80'],
            'speaker_session_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.audio_chunk_id' => ['nullable', 'integer', 'min:1'],
            'sections.*.prepared_name' => ['nullable', 'string', 'regex:/^chunk_\d+(?:-speech)?\.wav$/i'],
            'sections.*.clip_index' => ['required', 'integer', 'min:1'],
            'sections.*.clip_start_ms' => ['required', 'integer', 'min:0'],
            'sections.*.clip_end_ms' => ['required', 'integer', 'min:0'],
            'sections.*.range_label' => ['required', 'string', 'max:32'],
            'sections.*.duration_ms' => ['required', 'integer', 'min:1'],
        ]);

        $result = $diarization->queuePreparedSections(
            (string) $validated['upload_session_id'],
            (string) $validated['speaker_session_id'],
            (int) ($validated['user_id'] ?? 1),
            trim((string) $validated['category_name']),
            array_values($validated['sections']),
        );

        return response()->json([
            'message' => 'queued',
            'data' => [
                'audio_chunk_ids' => $result['queued'],
                'failed_audio_chunk_ids' => $result['failed'],
                'sections' => $result['sections'],
            ],
        ]);
    }

    public function storeBatch(Request $request, AudioChunkIngestionService $ingestion): JsonResponse
    {
        return $this->ingestionResponse(
            $ingestion->storeUploadedBatch($request->validate([
                'upload_session_id' => ['required', 'string', 'max:80'],
                'user_id' => ['nullable', 'integer', 'min:1'],
                'category_name' => ['required', 'string', 'max:120'],
                'language_code' => ['nullable', 'string', 'max:32'],
                'transcription_engine' => ['nullable', 'string', Rule::in([TranscriptionEngine::Online->value])],
                'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
                'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'progress_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'finalize_session' => ['nullable', 'boolean'],
                'sections' => ['required', 'array', 'min:1', 'max:20'],
                'sections.*.clip_index' => ['required', 'integer', 'min:1'],
                'sections.*.clip_start_ms' => ['required', 'integer', 'min:0'],
                'sections.*.clip_end_ms' => ['required', 'integer', 'min:0'],
                'sections.*.range_label' => ['required', 'string', 'max:32'],
                'sections.*.duration_ms' => ['required', 'integer', 'min:1'],
                'sections.*.audio_chunk_id' => ['nullable', 'integer', 'min:1'],
                'sections.*.prepared_name' => ['nullable', 'string', 'regex:/^chunk_\d+(?:-speech)?\.wav$/i'],
                'sections.*.source_name' => ['nullable', 'string', 'regex:/^chunk_\d+\.wav$/i'],
                'sections.*.prepared_skipped' => ['nullable', 'boolean'],
            ])),
        );
    }

    public function audio(int $audioChunk, AudioChunkPersistenceService $audioChunks): Response|BinaryFileResponse
    {
        $audio = $audioChunks->audioPayload($audioChunk);

        if ($audio === null) {
            abort(404);
        }

        if ($audio['type'] === 'file') {
            return response()->file($audio['path'], [
                'Content-Type' => $audio['mime_type'],
                'Content-Length' => (string) $audio['size'],
            ]);
        }

        return response($audio['contents'], 200)
            ->header('Content-Type', $audio['mime_type'])
            ->header('Content-Length', (string) $audio['size']);
    }

    public function destroy(int $audioChunk, AudioChunkPersistenceService $audioChunks): JsonResponse
    {
        $result = $audioChunks->destroy($audioChunk);

        return response()->json($result['data'], $result['status']);
    }

    public function releaseSpeakerSession(
        Request $request,
        SpeakerDiarizationService $speakerDiarization,
    ): JsonResponse {
        $validated = $request->validate([
            'speaker_session_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $speakerDiarization->releaseSession((string) $validated['speaker_session_id']);

        return response()->json(['message' => 'released']);
    }

    private function uploadedSectionRules(): array
    {
        return [
            'upload_session_id' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'clip_index' => ['required', 'integer', 'min:1'],
            'clip_start_ms' => ['required', 'integer', 'min:0'],
            'clip_end_ms' => ['required', 'integer', 'min:0'],
            'range_label' => ['required', 'string', 'max:32'],
            'duration_ms' => ['required', 'integer', 'min:1'],
            'language_code' => ['nullable', 'string', 'max:32'],
            'transcription_engine' => ['nullable', 'string', Rule::in(TranscriptionEngine::values())],
            'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
            'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'progress_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'finalize_session' => ['nullable', 'boolean'],
            'prepared_name' => ['nullable', 'string', 'regex:/^chunk_\d+(?:-speech)?\.wav$/i'],
            'source_name' => ['nullable', 'string', 'regex:/^chunk_\d+\.wav$/i'],
            'prepared_skipped' => ['nullable', 'boolean'],
            'audio_chunk_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function ingestionResponse(AudioChunkIngestionResult $result): JsonResponse
    {
        $status = match ($result->type) {
            AudioChunkIngestionResult::SAVED => 201,
            AudioChunkIngestionResult::SKIPPED => 200,
            AudioChunkIngestionResult::REJECTED => 422,
            AudioChunkIngestionResult::FAILED => 500,
            default => 500,
        };

        return response()->json($result->toResponsePayload(), $status);
    }
}
