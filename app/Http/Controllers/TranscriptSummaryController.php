<?php

namespace App\Http\Controllers;

use App\Exceptions\TranscriptPolisherException;
use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;
use App\Models\TranscriptSummary;
use App\Services\TranscriptSummarizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TranscriptSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
        ]);

        $summary = TranscriptSummary::query()
            ->where('user_id', (int) ($validated['user_id'] ?? 1))
            ->where('category_name', trim((string) $validated['category_name']))
            ->first();

        return response()->json(['data' => $this->responseRow($summary, $validated['category_name'])]);
    }

    public function store(Request $request, TranscriptSummarizerService $summarizer): JsonResponse
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'in:raw,cleaned'],
        ]);
        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $sourceType = (string) ($validated['source_type'] ?? 'raw');
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

            return response()->json(['message' => $message], 404);
        }

        try {
            $result = $summarizer->summarize($transcript);

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

            return response()->json(['message' => $message], $this->errorStatus($exception));
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
            return response()->json(['message' => 'This summary was replaced by a newer request.'], 409);
        }

        $summary = TranscriptSummary::query()
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->first();

        return response()->json(['message' => 'summarized', 'data' => $this->responseRow($summary, $categoryName)]);
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

    private function errorStatus(Throwable $exception): int
    {
        $status = (int) $exception->getCode();

        return $status >= 400 && $status <= 599 ? $status : 422;
    }
}
