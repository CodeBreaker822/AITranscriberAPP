<?php

namespace App\Services\Transcripts;

use App\Exceptions\TranscriptPolisherException;
use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;
use App\Models\TranscriptSummary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TranscriptSummaryService
{
    public function __construct(private readonly TranscriptSummarizerService $summarizer) {}

    public function summarizeProject(int $userId, string $categoryName, string $sourceType = 'raw'): array
    {
        $sourceType = $sourceType === 'cleaned' ? 'cleaned' : 'raw';
        $runToken = (string) Str::uuid();
        $now = now();

        TranscriptSummary::query()->updateOrCreate(
            ['user_id' => $userId, 'category_name' => $categoryName],
            [
                'summary_text' => null,
                'source_type' => $sourceType,
                'provider' => null,
                'model' => null,
                'status' => 'processing',
                'error_message' => null,
                'run_token' => $runToken,
                'started_at' => $now,
                'completed_at' => null,
            ],
        );

        $transcript = $this->wholeTranscript($userId, $categoryName, $sourceType);

        if ($transcript === '') {
            $label = $sourceType === 'cleaned' ? 'cleaned' : 'raw';
            $message = "No {$label} transcript is available to summarize.";
            $this->markFailed($userId, $categoryName, $runToken, $message);

            throw new TranscriptPolisherException($message, 404);
        }

        try {
            $result = $this->summarizer->summarize($transcript);

            if (trim((string) ($result['text'] ?? '')) === '') {
                throw new TranscriptPolisherException(
                    'The transcription server returned a successful response without summary text.'
                );
            }
        } catch (Throwable $exception) {
            $message = $exception instanceof TranscriptPolisherException
                ? $exception->getMessage()
                : 'The transcript could not be summarized. Please try again.';

            $this->markFailed($userId, $categoryName, $runToken, $message);
            Log::error('Transcript summarization failed.', [
                'message' => $message,
                'category_name' => $categoryName,
            ]);

            throw $exception instanceof TranscriptPolisherException
                ? $exception
                : new TranscriptPolisherException($message, 422);
        }

        $updated = TranscriptSummary::query()
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->where('run_token', $runToken)
            ->update([
                'summary_text' => $result['text'],
                'provider' => $result['provider'],
                'model' => $result['model'],
                'status' => 'complete',
                'error_message' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new TranscriptPolisherException('This summary was replaced by a newer request.', 409);
        }

        $summary = TranscriptSummary::query()
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->first();

        return ['message' => 'summarized', 'data' => $this->responseRow($summary, $categoryName)];
    }

    public function findSummary(int $userId, string $categoryName): array
    {
        $summary = TranscriptSummary::query()
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->first();

        return $this->responseRow($summary, $categoryName);
    }

    public function markProcessing(int $userId, string $categoryName, string $sourceType): array
    {
        TranscriptSummary::query()->updateOrCreate(
            ['user_id' => $userId, 'category_name' => $categoryName],
            [
                'summary_text' => null,
                'source_type' => $sourceType === 'cleaned' ? 'cleaned' : 'raw',
                'provider' => null,
                'model' => null,
                'status' => 'processing',
                'error_message' => null,
                'run_token' => (string) Str::uuid(),
                'started_at' => now(),
                'completed_at' => null,
            ],
        );

        return ['message' => 'queued', 'data' => $this->findSummary($userId, $categoryName)];
    }

    private function markFailed(int $userId, string $categoryName, string $runToken, string $message): void
    {
        TranscriptSummary::query()
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->where('run_token', $runToken)
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function responseRow(?TranscriptSummary $summary, string $categoryName): array
    {
        return [
            'category_name' => trim($categoryName),
            'source_type' => $summary?->source_type ?? 'raw',
            'status' => $summary?->status ?? 'idle',
            'summary_text' => $summary?->summary_text ?? '',
            'error_message' => (string) ($summary?->error_message ?? ''),
            'provider' => $summary?->provider,
            'model' => $summary?->model,
            'started_at' => $summary?->started_at,
            'completed_at' => $summary?->completed_at,
        ];
    }

    private function wholeTranscript(int $userId, string $categoryName, string $sourceType): string
    {
        if ($sourceType === 'cleaned') {
            $chunks = CleanTranscriptChunk::query()
                ->where('user_id', $userId)
                ->where('category_name', $categoryName)
                ->whereNotNull('clean_text')
                ->orderBy('clip_start_ms')
                ->get(['range_label', 'clean_text']);
        } else {
            $chunks = AudioChunk::query()
                ->where('user_id', $userId)
                ->where('category_name', $categoryName)
                ->whereNotNull('translated_text')
                ->orderBy('clip_start_ms')
                ->get(['range_label', 'translated_text']);
        }

        return $chunks
            ->filter(fn ($chunk): bool => trim((string) $this->transcriptText($chunk, $sourceType)) !== '')
            ->map(fn ($chunk): string => trim((string) $chunk->range_label)."\n".$this->transcriptText($chunk, $sourceType))
            ->implode("\n\n");
    }

    private function transcriptText(AudioChunk|CleanTranscriptChunk $chunk, string $sourceType): string
    {
        return (string) ($sourceType === 'cleaned' ? $chunk->clean_text : $chunk->translated_text);
    }
}
