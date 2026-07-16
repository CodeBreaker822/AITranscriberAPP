<?php

namespace App\Http\Controllers;

use App\Exceptions\TranscriptPolisherException;
use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;
use App\Services\BackgroundJobs\BackgroundJobDispatcher;
use App\Services\Transcripts\TranscriptFurnishService;
use App\Services\Transcripts\TranscriptPolisherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TranscriptFurnishController extends Controller
{
    private const FURNISH_WINDOW_MS = 5 * 60 * 1000;

    private const POLISH_REQUEST_INTERVAL_SECONDS = 4;

    public function store(
        Request $request,
        TranscriptPolisherService $polisher,
        TranscriptFurnishService $furnisher,
        BackgroundJobDispatcher $backgroundJobs,
    ): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'window_index' => ['nullable', 'integer', 'min:0'],
            'audio_chunk_ids' => ['nullable', 'array', 'min:1', 'max:20'],
            'audio_chunk_ids.*' => ['required', 'integer', 'min:1'],
            'instructions' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $instructions = trim((string) $validated['instructions']);
        $instructionHash = hash('sha256', $instructions);
        $requestCount = 0;

        if (isset($validated['audio_chunk_ids']) && is_array($validated['audio_chunk_ids'])) {
            $audioChunkIds = array_values(array_unique(array_map('intval', $validated['audio_chunk_ids'])));

            if ($backgroundJobs->wantsBackgroundJob($request)) {
                return $backgroundJobs->response('polish_transcript_chunks', [
                    'user_id' => $userId,
                    'category_name' => $categoryName,
                    'audio_chunk_ids' => $audioChunkIds,
                    'instructions' => $instructions,
                ]);
            }

            try {
                return response()->json($furnisher->polishAudioChunks($userId, $categoryName, $audioChunkIds, $instructions));
            } catch (TranscriptPolisherException $exception) {
                return $this->furnishFailure($exception, $categoryName);
            }
        }

        if (array_key_exists('window_index', $validated) && $validated['window_index'] !== null) {
            $windowIndex = (int) $validated['window_index'];
            $windowStartMs = $windowIndex * self::FURNISH_WINDOW_MS;
            $windowEndMs = $windowStartMs + self::FURNISH_WINDOW_MS;
            $windowChunks = $this->rawChunkQuery($userId, $categoryName)
                ->where('clip_start_ms', '>=', $windowStartMs)
                ->where('clip_start_ms', '<', $windowEndMs)
                ->get()
                ->all();

            if ($windowChunks === []) {
                return response()->json([
                    'message' => 'polished',
                    'data' => [],
                    'count' => 0,
                    'polish_requests' => $requestCount,
                    'window_index' => $windowIndex,
                ]);
            }

            try {
                $cleaned = $this->furnishWindow($windowChunks, $polisher, $userId, $categoryName, $instructions, $instructionHash, $requestCount);
            } catch (TranscriptPolisherException $exception) {
                return $this->furnishFailure($exception, $categoryName);
            }

            return response()->json([
                'message' => 'polished',
                'data' => $cleaned,
                'count' => count($cleaned),
                'polish_requests' => $requestCount,
                'window_index' => $windowIndex,
            ]);
        }

        $chunks = $this->rawChunkQuery($userId, $categoryName);

        $cleaned = [];
        $hasChunks = false;
        $currentWindow = null;
        $windowChunks = [];

        foreach ($chunks->cursor() as $chunk) {
            $hasChunks = true;
            $window = intdiv((int) $chunk->clip_start_ms, self::FURNISH_WINDOW_MS);

            if ($currentWindow !== null && $window !== $currentWindow) {
                try {
                    array_push(
                        $cleaned,
                        ...$this->furnishWindow($windowChunks, $polisher, $userId, $categoryName, $instructions, $instructionHash, $requestCount),
                    );
                } catch (TranscriptPolisherException $exception) {
                    return $this->furnishFailure($exception, $categoryName);
                }

                $windowChunks = [];
            }

            $currentWindow = $window;
            $windowChunks[] = $chunk;
        }

        if (! $hasChunks) {
            return response()->json([
                'message' => 'No raw transcript is available to polish.',
            ], 404);
        }

        if ($windowChunks !== []) {
            try {
                array_push(
                    $cleaned,
                    ...$this->furnishWindow($windowChunks, $polisher, $userId, $categoryName, $instructions, $instructionHash, $requestCount),
                );
            } catch (TranscriptPolisherException $exception) {
                return $this->furnishFailure($exception, $categoryName);
            }
        }

        return response()->json([
            'message' => 'polished',
            'data' => $cleaned,
            'count' => count($cleaned),
            'polish_requests' => $requestCount,
        ]);
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
            ->orderBy('clip_start_ms');
    }

    private function furnishWindow(
        array $windowChunks,
        TranscriptPolisherService $polisher,
        int $userId,
        string $categoryName,
        string $instructions,
        string $instructionHash,
        int &$requestCount,
    ): array {
        if ($this->windowHasText($windowChunks) && $requestCount > 0) {
            sleep(self::POLISH_REQUEST_INTERVAL_SECONDS);
        }

        $chunksToClean = $windowChunks;
        $result = [
            'chunks' => [],
            'provider' => null,
            'model' => null,
        ];

        if ($chunksToClean !== []) {
            $result = $polisher->polishChunks($this->toPolishChunks($chunksToClean), [
                'instructions' => $instructions,
            ]);

            if ($this->windowHasText($chunksToClean)) {
                $requestCount++;
            }
        }

        $newCleanedById = collect($result['chunks'])->keyBy('audio_chunk_id');

        $cleaned = [];

        // Keep the last known-good polished transcript until the hosted response
        // has been fully validated. A failed retry must not destroy good output.
        $this->removeExistingCleanedChunks($windowChunks);

        foreach ($windowChunks as $chunk) {
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

            $cleaned[] = $this->cleanedResponseRow($chunk, [
                'text' => $cleanedChunk['text'],
                'timestamps' => $cleanedChunk['timestamps'],
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
            ]);
        }

        return $cleaned;
    }

    private function removeExistingCleanedChunks(array $windowChunks): void
    {
        $ids = array_map(fn ($chunk): int => (int) $chunk->id, $windowChunks);

        if ($ids === []) {
            return;
        }

        CleanTranscriptChunk::query()
            ->whereIn('audio_chunk_id', $ids)
            ->delete();
    }

    private function cleanedResponseRow($chunk, array $cleanedChunk): array
    {
        return [
            'audio_chunk_id' => $chunk->id,
            'clip_index' => (int) $chunk->clip_index,
            'clip_start_ms' => (int) $chunk->clip_start_ms,
            'clip_end_ms' => (int) $chunk->clip_end_ms,
            'range_label' => $chunk->range_label,
            'clean_text' => $cleanedChunk['text'],
            'clean_timestamps' => $cleanedChunk['timestamps'],
            'provider' => $cleanedChunk['provider'],
            'model' => $cleanedChunk['model'],
        ];
    }

    private function furnishFailure(TranscriptPolisherException $exception, string $categoryName): JsonResponse
    {
        Log::error('Transcript polishing failed.', [
            'message' => $exception->getMessage(),
            'category_name' => $categoryName,
        ]);

        $status = (int) $exception->getCode();

        return response()->json([
            'message' => $exception->getMessage(),
        ], $status >= 400 && $status <= 599 ? $status : 422);
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
