<?php

namespace App\Http\Controllers;

use App\Services\BackgroundJobs\BackgroundJobStore;
use Illuminate\Http\JsonResponse;

class UploadBackgroundJobController extends Controller
{
    public function show(string $job, BackgroundJobStore $jobs): JsonResponse
    {
        $record = $jobs->read($job);
        $status = (string) ($record['status'] ?? 'queued');

        return response()->json([
            'id' => $record['id'] ?? $job,
            'status' => $status,
            'message' => $record['message'] ?? null,
            'response' => $record['response'] ?? null,
            'http_status' => $record['http_status'] ?? null,
        ], $status === 'completed' ? 200 : 202);
    }

    public function cancel(string $job, BackgroundJobStore $jobs): JsonResponse
    {
        $record = $jobs->cancel($job);

        return response()->json([
            'id' => $record['id'] ?? $job,
            'status' => $record['status'] ?? 'cancelled',
        ]);
    }
}
