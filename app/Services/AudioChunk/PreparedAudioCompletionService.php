<?php

namespace App\Services\AudioChunk;

use App\Enums\AudioChunkStatus;
use App\Models\AudioChunk;

class PreparedAudioCompletionService
{
    public function __construct(
        private readonly AudioChunkPersistenceService $persistence,
        private readonly UploadedDiarizationService $uploadedDiarization,
    ) {}

    public function complete(
        int $audioChunkId,
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        string $sourceType,
        string $storageSessionId,
        string $speakerSessionId,
    ): array {
        $result = $this->preparedCompletionResult($audioChunkId, $storedAudio, $transcription, $speakerSessionId);

        return $this->persistence->completePreparedAudioTranscription(
            $audioChunkId,
            $validated,
            $storedAudio,
            $result['transcription'],
            $userId,
            $categoryName,
            $sourceType,
            $storageSessionId,
            $result['status'],
        );
    }

    /**
     * @return array{transcription: array{text: string, timestamps: array<int, array<string, mixed>>}, status: string}
     */
    private function preparedCompletionResult(
        int $audioChunkId,
        array $storedAudio,
        array $transcription,
        string $speakerSessionId,
    ): array {
        $transcription = $this->normalizedTranscription($transcription);
        $audioChunk = AudioChunk::query()->find($audioChunkId);

        if (! $audioChunk) {
            return [
                'transcription' => $transcription,
                'status' => AudioChunkStatus::Transcribed->value,
            ];
        }

        $status = (string) $audioChunk->status;
        $hasDiarizationResult = $this->uploadedDiarization->hasPreparedResult((string) $storedAudio['path']);
        $merged = $status === AudioChunkStatus::DiarizationFailed->value
            ? $transcription
            : $this->uploadedDiarization->mergePreparedResultIfAvailable(
                $audioChunk,
                (string) $storedAudio['path'],
                $transcription,
                $speakerSessionId,
            );

        return [
            'transcription' => $this->normalizedTranscription($merged),
            'status' => $status === AudioChunkStatus::DiarizationFailed->value
                ? AudioChunkStatus::DiarizationFailed->value
                : ($hasDiarizationResult || $status === AudioChunkStatus::DiarizationReady->value
                    ? AudioChunkStatus::Transcribed->value
                    : AudioChunkStatus::DiarizationQueued->value),
        ];
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizedTranscription(array $transcription): array
    {
        return [
            'text' => (string) ($transcription['text'] ?? ''),
            'timestamps' => is_array($transcription['timestamps'] ?? null)
                ? array_values(array_filter($transcription['timestamps'], 'is_array'))
                : [],
        ];
    }
}
