<?php

namespace App\Services\AudioChunk;

use App\Enums\AudioChunkStatus;
use App\Models\AudioChunk;
use App\Services\ServiceUserMessage;
use App\Services\StoredAudioService;
use RuntimeException;

class AudioChunkPersistenceService
{
    public function __construct(
        private readonly StoredAudioService $storedAudio,
        private readonly AudioChunkPayloadService $payloads,
    ) {}

    public function listRows(): array
    {
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
            ->map(fn (AudioChunk $row): array => $this->responseRow($row));

        return [
            'data' => $rows,
            'count' => $rows->count(),
        ];
    }

    public function statusRows(array $audioChunkIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (mixed $id): int => (int) $id, $audioChunkIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [
                'data' => [],
                'count' => 0,
            ];
        }

        $positions = array_flip($ids);

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
            ])
            ->whereKey($ids)
            ->get()
            ->map(fn (AudioChunk $row): array => $this->responseRow($row))
            ->sortBy(fn (array $row): int => $positions[(int) $row['id']] ?? PHP_INT_MAX)
            ->values();

        return [
            'data' => $rows,
            'count' => $rows->count(),
        ];
    }

    public function audioPayload(int $audioChunk): ?array
    {
        $row = AudioChunk::query()->find($audioChunk);

        if (! $row) {
            return null;
        }

        $audioPath = $this->storedAudio->absolutePath($row->audio_path ?? null);

        if ($audioPath !== null) {
            return [
                'type' => 'file',
                'path' => $audioPath,
                'mime_type' => $row->mime_type ?: 'audio/flac',
                'size' => filesize($audioPath) ?: 0,
            ];
        }

        $audioBlob = is_string($row->audio_blob ?? null) ? $row->audio_blob : '';

        if ($audioBlob === '') {
            return null;
        }

        return [
            'type' => 'blob',
            'contents' => $audioBlob,
            'mime_type' => $row->mime_type ?: 'audio/webm',
            'size' => strlen($audioBlob),
        ];
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
        string $status = AudioChunkStatus::Transcribed->value,
        bool $includePreparedMetadata = true,
    ): array {
        if (! is_file($storedAudio['path'])) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $transcription = [
            'text' => (string) ($transcription['text'] ?? ''),
            'timestamps' => is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
        ];
        $audioChunk = $this->createAudioChunkRecord(
            $validated,
            $storedAudio,
            $userId,
            $categoryName,
            $transcription['text'],
            $transcription['timestamps'],
            $status,
        );
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

        $transcription = [
            'text' => '',
            'timestamps' => [],
        ];
        $status = AudioChunkStatus::DiarizationReady->value;
        $audioChunk = $this->createAudioChunkRecord(
            $validated,
            $storedAudio,
            $userId,
            $categoryName,
            null,
            $transcription['timestamps'],
            $status,
        );

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
            ),
            'status' => $status,
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
        string $finalStatus,
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

        $audioChunk->forceFill([
            'translated_text' => $transcription['text'],
            'transcription_timestamps' => $transcription['timestamps'],
            'status' => $finalStatus,
        ])->save();

        return [
            ...$this->payloads->responseData(
                (int) $audioChunk->id,
                $validated,
                $storedAudio,
                $transcription,
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

    private function responseRow(AudioChunk $row): array
    {
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
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $storedAudio
     * @param array<int, array<string, mixed>> $timestamps
     */
    private function createAudioChunkRecord(
        array $validated,
        array $storedAudio,
        int $userId,
        string $categoryName,
        ?string $translatedText,
        array $timestamps,
        string $status,
    ): AudioChunk {
        return AudioChunk::query()->create([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => (string) $storedAudio['mime_type'],
            'original_name' => (string) $storedAudio['name'],
            'file_size_bytes' => (int) $storedAudio['size'],
            'audio_blob' => '',
            'translated_text' => $translatedText,
            'transcription_timestamps' => $timestamps,
            'status' => $status,
        ]);
    }
}
