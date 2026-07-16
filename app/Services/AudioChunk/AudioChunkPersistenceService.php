<?php

namespace App\Services\AudioChunk;

use App\Enums\AudioChunkStatus;
use App\Models\AudioChunk;
use App\Services\Support\ServiceUserMessage;
use RuntimeException;

class AudioChunkPersistenceService
{
    private const ROW_COLUMNS = [
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
    ];

    public function __construct(
        private readonly AudioChunkPayloadService $payloads,
        private readonly AudioChunkRowPresenter $rows,
        private readonly AudioChunkAudioPayloadService $audioPayloads,
        private readonly AudioChunkRecordStore $records,
    ) {}

    public function listRows(): array
    {
        $rows = AudioChunk::query()
            ->select(self::ROW_COLUMNS)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AudioChunk $row): array => $this->rows->row($row));

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
            ->select(self::ROW_COLUMNS)
            ->whereKey($ids)
            ->get()
            ->map(fn (AudioChunk $row): array => $this->rows->row($row))
            ->sortBy(fn (array $row): int => $positions[(int) $row['id']] ?? PHP_INT_MAX)
            ->values();

        return [
            'data' => $rows,
            'count' => $rows->count(),
        ];
    }

    public function audioPayload(int $audioChunk): ?array
    {
        return $this->audioPayloads->payload($audioChunk);
    }

    public function destroy(int $audioChunk): array
    {
        if (! $this->records->delete($audioChunk)) {
            return [
                'status' => 404,
                'data' => ['message' => 'not found'],
            ];
        }

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
        $transcription = [
            'text' => (string) ($transcription['text'] ?? ''),
            'timestamps' => is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
        ];

        return $this->storeAudioChunk(
            $validated,
            $storedAudio,
            $transcription,
            $userId,
            $categoryName,
            $transcription['text'],
            $sourceType,
            $storageSessionId,
            $status,
            $includePreparedMetadata,
        );
    }

    public function storePreparedAudioForDiarization(
        array $validated,
        array $storedAudio,
        int $userId,
        string $categoryName,
        string $sourceType,
        string $storageSessionId,
    ): array {
        return $this->storeAudioChunk(
            $validated,
            $storedAudio,
            ['text' => '', 'timestamps' => []],
            $userId,
            $categoryName,
            null,
            $sourceType,
            $storageSessionId,
            AudioChunkStatus::DiarizationReady->value,
        );
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

    private function storeAudioChunk(
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        ?string $translatedText,
        string $sourceType,
        string $storageSessionId,
        string $status,
        bool $includePreparedMetadata = true,
    ): array {
        if (! is_file($storedAudio['path'])) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $timestamps = is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [];
        $audioChunk = $this->records->create(
            $validated,
            $storedAudio,
            $userId,
            $categoryName,
            $translatedText,
            $timestamps,
            $status,
        );
        $audioChunkId = (int) $audioChunk->id;
        $this->records->attachStoredAudio($audioChunkId, $storedAudio['path'], $storageSessionId);

        return [
            ...$this->payloads->responseData(
                $audioChunkId,
                $validated,
                $storedAudio,
                [
                    'text' => (string) ($transcription['text'] ?? ''),
                    'timestamps' => $timestamps,
                ],
                $userId,
                $categoryName,
                $sourceType,
                $includePreparedMetadata,
            ),
            'status' => $status,
        ];
    }

}
