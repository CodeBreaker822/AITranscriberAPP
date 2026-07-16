<?php

namespace App\Services\AudioChunk;

class UploadedDiarizationResultStore
{
    public function has(string $audioPath): bool
    {
        return is_file($this->path($audioPath));
    }

    /** @return array<int, array<string, mixed>> */
    public function read(string $audioPath): array
    {
        if (! $this->has($audioPath)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path($audioPath)), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<int, array<string, mixed>> $segments */
    public function write(string $audioPath, array $segments): void
    {
        $encoded = json_encode(array_values($segments), JSON_UNESCAPED_SLASHES);

        if (is_string($encoded)) {
            @file_put_contents($this->path($audioPath), $encoded);
        }
    }

    public function delete(string $audioPath): void
    {
        @unlink($this->path($audioPath));
    }

    private function path(string $audioPath): string
    {
        return $audioPath.'.diarization.json';
    }
}
