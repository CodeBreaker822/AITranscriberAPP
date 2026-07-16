<?php

namespace App\Services\Audio;

use App\Services\Support\ServiceUserMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class AudioUploadSessionStore
{
    public function __construct(private readonly AudioDurationProbe $durations)
    {
    }

    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createFromUploadedFile(UploadedFile $file): array
    {
        $sessionId = (string) Str::uuid();
        $directory = $this->directory($sessionId);
        File::ensureDirectoryExists($directory);

        $sourcePath = $directory.DIRECTORY_SEPARATOR.'source.'.$this->extension($file);
        $file->move($directory, basename($sourcePath));

        return $this->write($sessionId, $sourcePath, $this->durations->milliseconds($sourcePath));
    }

    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createFromPath(string $sourcePath, int $knownDurationMs = 0): array
    {
        $sourcePath = trim($sourcePath);

        if ($sourcePath === '' || ! is_file($sourcePath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $realPath = (new SplFileInfo($sourcePath))->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $durationMs = $knownDurationMs > 0
            ? $knownDurationMs
            : $this->durations->milliseconds($realPath);

        return $this->write((string) Str::uuid(), $realPath, $durationMs, 'local_path');
    }

    /**
     * @return array{source_path: string, duration_ms: int, created_at?: string, source_mode?: string}
     */
    public function read(string $sessionId): array
    {
        $path = $this->directory($sessionId).DIRECTORY_SEPARATOR.'session.json';

        if (! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::uploadSessionExpired());
        }

        $session = json_decode((string) file_get_contents($path), true);

        if (! is_array($session) || empty($session['source_path']) || ! is_file($session['source_path'])) {
            throw new RuntimeException(ServiceUserMessage::uploadSessionExpired());
        }

        return $session;
    }

    public function available(string $sessionId): bool
    {
        try {
            $this->read($sessionId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Delete a completed upload session without touching local-path source files. */
    public function cleanup(string $sessionId): void
    {
        $sessionRoot = realpath($this->rootDirectory());
        $directory = realpath($this->directory($sessionId));

        if (! is_string($sessionRoot) || ! is_string($directory)) {
            return;
        }

        $sessionPrefix = rtrim($sessionRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($directory, $sessionPrefix) && $directory !== $sessionRoot) {
            File::deleteDirectory($directory);
        }
    }

    public function directory(string $sessionId): string
    {
        return $this->rootDirectory().'/'.$sessionId;
    }

    private function rootDirectory(): string
    {
        return storage_path('app/private/audio-upload-sessions');
    }

    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    private function write(string $sessionId, string $sourcePath, int $durationMs, ?string $sourceMode = null): array
    {
        $directory = $this->directory($sessionId);
        File::ensureDirectoryExists($directory);

        $metadata = [
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
            'created_at' => now()->toISOString(),
        ];

        if ($sourceMode !== null) {
            $metadata['source_mode'] = $sourceMode;
        }

        file_put_contents($directory.DIRECTORY_SEPARATOR.'session.json', json_encode($metadata));

        return [
            'session_id' => $sessionId,
            'directory' => $directory,
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
        ];
    }

    private function extension(UploadedFile $file): string
    {
        return $file->getClientOriginalExtension() ?: 'audio';
    }
}
