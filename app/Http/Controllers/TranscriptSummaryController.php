<?php

namespace App\Http\Controllers;

use App\Exceptions\TranscriptPolisherException;
use App\Services\BackgroundJobs\BackgroundJobDispatcher;
use App\Services\Transcripts\TranscriptSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranscriptSummaryController extends Controller
{
    public function show(Request $request, TranscriptSummaryService $summaries): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $summaries->findSummary(
                (int) ($validated['user_id'] ?? 1),
                trim((string) $validated['category_name']),
            ),
        ]);
    }

    public function store(
        Request $request,
        TranscriptSummaryService $summaries,
        BackgroundJobDispatcher $backgroundJobs,
    ): JsonResponse
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

        if ($backgroundJobs->wantsBackgroundJob($request)) {
            $processing = $summaries->markProcessing($userId, $categoryName, $sourceType);
            $job = $backgroundJobs->dispatch('summarize_transcript', [
                'user_id' => $userId,
                'category_name' => $categoryName,
                'source_type' => $sourceType,
            ]);

            return response()->json([
                'background' => true,
                'job_id' => $job['id'],
                'status' => $job['status'],
                'status_url' => route('background-jobs.show', ['job' => $job['id']]),
                'cancel_url' => route('background-jobs.cancel', ['job' => $job['id']]),
                ...$processing,
            ], 202);
        }

        try {
            return response()->json($summaries->summarizeProject($userId, $categoryName, $sourceType));
        } catch (TranscriptPolisherException $exception) {
            $status = (int) $exception->getCode();

            return response()->json([
                'message' => $exception->getMessage(),
            ], $status >= 400 && $status <= 599 ? $status : 422);
        }
    }
}
