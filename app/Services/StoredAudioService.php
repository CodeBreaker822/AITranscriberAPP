<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class StoredAudioService
{
    /**
     * @return array{audio_path: string, audio_size: int, audio_hash: string, mime_type: string}
     */
    public function persistWav(string $wavPath, string $sessionId, int $audioChunkId): array
    {
        if (! is_file($wavPath)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $safeSessionId = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($sessionId)) ?: 'recordings';
        $relativePath = 'audio/'.$safeSessionId.'/'.$audioChunkId.'.flac';
        $outputPath = storage_path('app/'.$relativePath);
        $temporaryPath = $outputPath.'.part';
        File::ensureDirectoryExists(dirname($outputPath));

        $process = new Process([
            base_path('ffmpeg/bin/ffmpeg.exe'),
            '-y',
            '-i',
            $wavPath,
            '-vn',
            '-c:a',
            'flac',
            '-compression_level',
            '8',
            '-f',
            'flac',
            $temporaryPath,
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($temporaryPath) || ! File::move($temporaryPath, $outputPath)) {
            File::delete($temporaryPath);
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $size = filesize($outputPath);
        $hash = hash_file('sha256', $outputPath);

        if (! is_int($size) || $size <= 0 || ! is_string($hash)) {
            File::delete($outputPath);
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'audio_path' => $relativePath,
            'audio_size' => $size,
            'audio_hash' => $hash,
            'mime_type' => 'audio/flac',
        ];
    }

    public function absolutePath(?string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', trim((string) $relativePath));

        if ($relativePath === '' || str_contains($relativePath, '..') || ! str_starts_with($relativePath, 'audio/')) {
            return null;
        }

        $path = storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        return is_file($path) ? $path : null;
    }

    public function delete(?string $relativePath): void
    {
        $path = $this->absolutePath($relativePath);

        if ($path === null) {
            return;
        }

        File::delete($path);
        $directory = dirname($path);
        $root = storage_path('app/audio');

        if ($directory !== $root && File::isDirectory($directory) && File::files($directory) === []) {
            File::deleteDirectory($directory);
        }
    }
}
