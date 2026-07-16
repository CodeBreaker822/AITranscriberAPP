<?php

namespace App\Services\Transcripts;

use App\Exceptions\TranscriptPolisherException;
use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;

class TranscriptFurnishService
{
    public function __construct(private readonly TranscriptPolisherService $polisher) {}

    /**
     * @param  array<int, int>  $audioChunkIds
     * @return array{message: string, data: array<int, array<string, mixed>>, count: int, polish_requests: int, audio_chunk_ids: array<int, int>}
     */
    public function polishAudioChunks(
        int $userId,
        string $categoryName,
        array $audioChunkIds,
        string $instructions,
    ): array {
        $audioChunkIds = array_values(array_unique(array_map('intval', $audioChunkIds)));
        $chunks = $this->rawChunkQuery($userId, $categoryName)
            ->whereKey($audioChunkIds)
            ->get()
            ->all();
        $requestCount = 0;

        if ($chunks === []) {
            return [
                'message' => 'polished',
                'data' => [],
                'count' => 0,
                'polish_requests' => $requestCount,
                'audio_chunk_ids' => $audioChunkIds,
            ];
        }

        $cleaned = $this->furnishChunks(
            $chunks,
            $userId,
            $categoryName,
            trim($instructions),
            hash('sha256', trim($instructions)),
            $requestCount,
        );

        return [
            'message' => 'polished',
            'data' => $cleaned,
            'count' => count($cleaned),
            'polish_requests' => $requestCount,
            'audio_chunk_ids' => $audioChunkIds,
        ];
    }

    private function rawChunkQuery(int $userId, string $categoryName)
    {
        return AudioChunk::query()
            ->select([
                'id',
                'user_id',
                'category_name',
                'clip_index',
                'clip_start_ms',
                'clip_end_ms',
                'range_label',
                'translated_text',
                'transcription_timestamps',
            ])
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->orderBy('clip_start_ms')
            ->orderBy('clip_index')
            ->orderBy('id');
    }

    private function furnishChunks(
        array $chunks,
        int $userId,
        string $categoryName,
        string $instructions,
        string $instructionHash,
        int &$requestCount,
    ): array {
        $result = $this->polisher->polishChunks($this->toPolishChunks($chunks), [
            'instructions' => $instructions,
        ]);

        if ($this->windowHasText($chunks)) {
            $requestCount++;
        }

        $newCleanedById = collect($result['chunks'])->keyBy('audio_chunk_id');
        $this->removeExistingCleanedChunks($chunks);
        $cleaned = [];

        foreach ($chunks as $chunk) {
            $cleanedChunk = $newCleanedById->get((int) $chunk->id);

            if (! $cleanedChunk) {
                continue;
            }

            CleanTranscriptChunk::query()->updateOrCreate(
                ['audio_chunk_id' => $chunk->id],
                [
                    'user_id' => $userId,
                    'category_name' => $categoryName,
                    'clip_index' => (int) $chunk->clip_index,
                    'clip_start_ms' => (int) $chunk->clip_start_ms,
                    'clip_end_ms' => (int) $chunk->clip_end_ms,
                    'range_label' => $chunk->range_label,
                    'raw_text' => $chunk->translated_text,
                    'clean_text' => $cleanedChunk['text'],
                    'clean_timestamps' => $cleanedChunk['timestamps'],
                    'provider' => $result['provider'] ?? null,
                    'model' => $result['model'] ?? null,
                    'instruction_hash' => $instructionHash,
                    'status' => 'cleaned',
                ],
            );

            $cleaned[] = [
                'audio_chunk_id' => $chunk->id,
                'clip_index' => (int) $chunk->clip_index,
                'clip_start_ms' => (int) $chunk->clip_start_ms,
                'clip_end_ms' => (int) $chunk->clip_end_ms,
                'range_label' => $chunk->range_label,
                'clean_text' => $cleanedChunk['text'],
                'clean_timestamps' => $cleanedChunk['timestamps'],
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
            ];
        }

        return $cleaned;
    }

    private function removeExistingCleanedChunks(array $chunks): void
    {
        $ids = array_map(fn ($chunk): int => (int) $chunk->id, $chunks);

        if ($ids === []) {
            return;
        }

        CleanTranscriptChunk::query()
            ->whereIn('audio_chunk_id', $ids)
            ->delete();
    }

    private function toPolishChunks(array $chunks): array
    {
        return array_map(
            fn ($chunk): array => [
                'id' => (int) $chunk->id,
                'clip_index' => (int) $chunk->clip_index,
                'range_label' => $chunk->range_label,
                'text' => (string) ($chunk->translated_text ?? ''),
                'timestamps' => $this->decodeTimestamps($chunk->transcription_timestamps),
            ],
            $chunks,
        );
    }

    private function windowHasText(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if (trim((string) ($chunk->translated_text ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function decodeTimestamps(mixed $timestamps): array
    {
        if (is_array($timestamps)) {
            return $timestamps;
        }

        if (! $timestamps) {
            return [];
        }

        $decoded = json_decode($timestamps, true);

        return is_array($decoded) ? $decoded : [];
    }
}
