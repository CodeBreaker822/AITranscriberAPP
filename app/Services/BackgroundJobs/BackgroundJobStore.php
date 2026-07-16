<?php

namespace App\Services\BackgroundJobs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackgroundJobStore
{
    public function create(string $action, array $payload): array
    {
        $id = (string) Str::uuid();
        $this->write($id, [
            'id' => $id,
            'action' => $action,
            'status' => 'queued',
            'payload' => $payload,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);

        return $this->read($id);
    }

    public function read(string $id): array
    {
        $path = $this->path($id);

        if (! is_file($path)) {
            abort(404);
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            abort(404);
        }

        return $payload;
    }

    public function markRunning(string $id): void
    {
        $this->patch($id, ['status' => 'running', 'started_at' => now()->toISOString()]);
    }

    public function markCompleted(string $id, array $response, int $httpStatus = 200): void
    {
        $this->patch($id, [
            'status' => 'completed',
            'response' => $response,
            'http_status' => $httpStatus,
            'finished_at' => now()->toISOString(),
        ]);
    }

    public function markFailed(string $id, string $message, int $httpStatus = 500): void
    {
        $this->patch($id, [
            'status' => 'failed',
            'message' => $message,
            'http_status' => $httpStatus,
            'finished_at' => now()->toISOString(),
        ]);
    }

    public function cancel(string $id): array
    {
        $job = $this->read($id);

        if (in_array($job['status'] ?? '', ['completed', 'failed', 'cancelled'], true)) {
            return $job;
        }

        $this->patch($id, [
            'status' => 'cancelled',
            'cancelled_at' => now()->toISOString(),
        ]);

        return $this->read($id);
    }

    public function cancelled(string $id): bool
    {
        try {
            return ($this->read($id)['status'] ?? '') === 'cancelled';
        } catch (\Throwable) {
            return true;
        }
    }

    private function patch(string $id, array $updates): void
    {
        $this->write($id, [
            ...$this->read($id),
            ...$updates,
            'updated_at' => now()->toISOString(),
        ]);
    }

    private function write(string $id, array $payload): void
    {
        $path = $this->path($id);
        File::ensureDirectoryExists(dirname($path));

        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode($payload, JSON_UNESCAPED_SLASHES));
        @rename($temporary, $path);
    }

    private function path(string $id): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9-]+/', '', $id) ?: 'invalid';

        return storage_path('app/private/background-jobs/'.$safeId.'.json');
    }
}
