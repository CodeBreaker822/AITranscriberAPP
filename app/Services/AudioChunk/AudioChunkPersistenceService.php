<?php

namespace App\Services\AudioChunk;

use App\Models\AudioChunk;
use App\Services\ServiceUserMessage;
use App\Services\StoredAudioService;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AudioChunkPersistenceService
{
    public function __construct(
        private readonly StoredAudioService $storedAudio,
        private readonly AudioChunkPayloadService $payloads,
    ) {}

    public function listRows(): array
    {
        $this->deleteNoSpeechRows();

        $rows = AudioChunk::query()
            ->select([
                'id',
                'clip_index',
                'clip_start_ms',
                'clip_end_ms',
                'range_label',
                'duration_ms',
                'category_name',
                'status',
                'original_name',
                'translated_text',
                'transcription_timestamps',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (AudioChunk $row): array {
                return [
                    'id' => $row->id,
                    'clip_index' => (int) $row->clip_index,
                    'clip_start_ms' => (int) $row->clip_start_ms,
                    'clip_end_ms' => (int) $row->clip_end_ms,
                    'range_label' => $row->range_label,
                    'duration_ms' => (int) $row->duration_ms,
                    'category_name' => $row->category_name ?: 'General',
                    'source_type' => $this->payloads->sourceType($row->original_name),
                    'status' => $row->status,
                    'play_url' => route('audio-chunks.audio', ['audioChunk' => $row->id]),
                    'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $row->id]),
                    'translated_text' => $row->translated_text ?? null,
                    'transcription_timestamps' => is_array($row->transcription_timestamps)
                        ? $row->transcription_timestamps
                        : [],
                ];
            });

        return [
            'data' => $rows,
            'count' => $rows->count(),
        ];
    }

    public function audioResponse(int $audioChunk): Response|BinaryFileResponse
    {
        $row = AudioChunk::query()->find($audioChunk);

        if (! $row) {
            abort(404);
        }

        $audioPath = $this->storedAudio->absolutePath($row->audio_path ?? null);

        if ($audioPath !== null) {
            return response()->file($audioPath, [
                'Content-Type' => $row->mime_type ?: 'audio/flac',
                'Content-Length' => (string) filesize($audioPath),
            ]);
        }

        $audioBlob = is_string($row->audio_blob ?? null) ? $row->audio_blob : '';

        if ($audioBlob === '') {
            abort(404);
        }

        $mimeType = $row->mime_type ?: 'audio/webm';

        return response($audioBlob, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Length', (string) strlen($audioBlob));
    }

    public function destroy(int $audioChunk): array
    {
        $row = AudioChunk::query()->find($audioChunk);

        if (! $row) {
            return [
                'status' => 404,
                'data' => ['message' => 'not found'],
            ];
        }

        $row->delete();
        $this->storedAudio->delete($row->audio_path ?? null);

        return [
            'status' => 200,
            'data' => [
                'message' => 'deleted',
                'id' => $audioChunk,
            ],
        ];
    }

    public function storeTranscribedAudio(
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        string $sourceType,
        string $storageSessionId,
        string $status = 'transcribed',
        bool $includePreparedMetadata = true,
    ): array {
        if (! is_file($storedAudio['path'])) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $audioChunk = AudioChunk::query()->create([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => $storedAudio['mime_type'],
            'original_name' => $storedAudio['name'],
            'file_size_bytes' => $storedAudio['size'],
            'audio_blob' => '',
            'translated_text' => $transcription['text'],
            'transcription_timestamps' => $transcription['timestamps'],
            'status' => $status,
        ]);
        $audioChunkId = (int) $audioChunk->id;
        $this->attachStoredAudio($audioChunkId, $storedAudio['path'], $storageSessionId);

        return [
            ...$this->payloads->responseData(
                $audioChunkId,
                $validated,
                $storedAudio,
                $transcription,
                $userId,
                $categoryName,
                $sourceType,
                $includePreparedMetadata,
            ),
            'status' => $status,
        ];
    }

    public function storePreparedAudioForDiarization(
        array $validated,
        array $storedAudio,
        int $userId,
        string $categoryName,
        string $sourceType,
        string $storageSessionId,
    ): array {
        if (! is_file($storedAudio['path'])) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $audioChunk = AudioChunk::query()->create([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => $storedAudio['mime_type'],
            'original_name' => $storedAudio['name'],
            'file_size_bytes' => $storedAudio['size'],
            'audio_blob' => '',
            'translated_text' => null,
            'transcription_timestamps' => [],
            'status' => 'diarization_ready',
        ]);

        $audioChunkId = (int) $audioChunk->id;
        $this->attachStoredAudio($audioChunkId, $storedAudio['path'], $storageSessionId);

        return [
            ...$this->payloads->responseData(
                $audioChunkId,
                $validated,
                $storedAudio,
                [
                    'text' => '',
                    'timestamps' => [],
                ],
                $userId,
                $categoryName,
                $sourceType,
            ),
            'status' => 'diarization_ready',
        ];
    }

    public function completePreparedAudioTranscription(
        int $audioChunkId,
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        string $sourceType,
        string $storageSessionId,
        string $speakerSessionId,
        UploadedDiarizationService $uploadedDiarization,
    ): array {
        $audioChunk = AudioChunk::query()->find($audioChunkId);

        if (! $audioChunk) {
            return $this->storeTranscribedAudio(
                $validated,
                $storedAudio,
                $transcription,
                $userId,
                $categoryName,
                $sourceType,
                $storageSessionId,
            );
        }

        $status = (string) $audioChunk->status;
        $hasDiarizationResult = $uploadedDiarization->hasPreparedResult((string) $storedAudio['path']);
        $merged = $status === 'diarization_failed'
            ? $transcription
            : $uploadedDiarization->mergePreparedResultIfAvailable(
                $audioChunk,
                $storedAudio['path'],
                $transcription,
                $speakerSessionId,
            );

        $finalStatus = $status === 'diarization_failed'
            ? 'diarization_failed'
            : ($hasDiarizationResult || $status === 'diarization_ready' ? 'transcribed' : 'diarization_queued');

        $audioChunk->forceFill([
            'translated_text' => $merged['text'],
            'transcription_timestamps' => $merged['timestamps'],
            'status' => $finalStatus,
        ])->save();

        return [
            ...$this->payloads->responseData(
                (int) $audioChunk->id,
                $validated,
                $storedAudio,
                $merged,
                $userId,
                $categoryName,
                $sourceType,
            ),
            'status' => $finalStatus,
        ];
    }

    private function attachStoredAudio(int $audioChunkId, string $wavPath, string $sessionId): void
    {
        try {
            $metadata = $this->storedAudio->persistWav($wavPath, $sessionId, $audioChunkId);
            AudioChunk::query()->whereKey($audioChunkId)->update([
                ...$metadata,
                'file_size_bytes' => $metadata['audio_size'],
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            AudioChunk::query()->whereKey($audioChunkId)->delete();

            throw $exception;
        }
    }

    private function deleteNoSpeechRows(): void
    {
        $rows = AudioChunk::query()
            ->whereNotNull('translated_text')
            ->get(['id', 'audio_path', 'translated_text']);
        $rows = $rows->filter(function (AudioChunk $row): bool {
            $text = strtolower(trim((string) $row->translated_text));

            return $text === '' || $text === 'no speech detected' || $text === 'no speech detected.';
        });

        if ($rows->isEmpty()) {
            return;
        }

        AudioChunk::query()->whereKey($rows->pluck('id')->all())->delete();
        $rows->each(function ($row): void {
            if (! empty($row->audio_path)) {
                $this->storedAudio->delete($row->audio_path);
            }
        });
    }
}
