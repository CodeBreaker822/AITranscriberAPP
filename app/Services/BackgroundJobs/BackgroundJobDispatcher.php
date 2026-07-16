<?php

namespace App\Services\BackgroundJobs;

use App\Jobs\RunBackgroundJob;
use Illuminate\Http\JsonResponse;

class BackgroundJobDispatcher
{
    public function __construct(private readonly BackgroundJobStore $jobs) {}

    public function dispatch(string $action, array $payload): array
    {
        $job = $this->jobs->create($action, $payload);
        RunBackgroundJob::dispatch($job['id'], $action, $payload);

        return $job;
    }

    public function response(string $action, array $payload): JsonResponse
    {
        $job = $this->dispatch($action, $payload);

        return response()->json([
            'background' => true,
            'job_id' => $job['id'],
            'status' => $job['status'],
            'status_url' => route('background-jobs.show', ['job' => $job['id']]),
            'cancel_url' => route('background-jobs.cancel', ['job' => $job['id']]),
        ], 202);
    }

    public function wantsBackgroundJob($request): bool
    {
        return $request->headers->get('X-AITranscriber-Background') === '1';
    }
}
