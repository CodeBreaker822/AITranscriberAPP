<?php

namespace App\Services\Audio;

use App\Services\Support\ServiceUserMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class AudioFileChunkerService
{
    public function __construct(
        private readonly AudioProcessRunner $processes,
        private readonly AudioSectionPlanner $sections,
        private readonly AudioDurationProbe $durations,
        private readonly AudioUploadSessionStore $sessions,
    ) {}

    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createSession(UploadedFile $file): array
    {
        return $this->sessions->createFromUploadedFile($file);
    }

    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createSessionFromPath(string $sourcePath, int $knownDurationMs = 0): array
    {
        return $this->sessions->createFromPath($sourcePath, $knownDurationMs);
    }

    /**
     * @return array{path: string, name: string, mime_type: string, size: int, duration_ms: int}
     */
    public function extractSegment(string $sessionId, int $clipIndex, int $startMs, int $durationMs): array
    {
        $session = $this->sessions->read($sessionId);
        $directory = $this->sessions->directory($sessionId);
        $outputPath = $directory.DIRECTORY_SEPARATOR.sprintf('chunk_%05d.wav', $clipIndex);

        $this->runProcess([
            $this->processes->ffmpegPath(),
            '-y',
            '-ss',
            sprintf('%.3f', $startMs / 1000),
            '-t',
            sprintf('%.3f', max(1, $durationMs) / 1000),
            '-i',
            $session['source_path'],
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ]);

        if (! is_file($outputPath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $preparedDurationMs = $this->durations->milliseconds($outputPath);

        return [
            'path' => $outputPath,
            'name' => basename($outputPath),
            'mime_type' => 'audio/wav',
            'size' => filesize($outputPath) ?: 0,
            'duration_ms' => $preparedDurationMs,
        ];
    }

    /**
     * @return array{path: string, name: string, mime_type: string, size: int, duration_ms: int}
     */
    public function sessionAudioFile(string $sessionId, string $name): array
    {
        $name = basename($name);

        if (preg_match('/^chunk_\d+(?:-speech)?\.wav$/i', $name) !== 1) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $this->sessions->read($sessionId);
        $path = $this->sessions->directory($sessionId).DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        return [
            'path' => $path,
            'name' => $name,
            'mime_type' => 'audio/wav',
            'size' => filesize($path) ?: 0,
            'duration_ms' => $this->durations->milliseconds($path),
        ];
    }

    /**
     * @return array{directory: string, path: string, name: string, mime_type: string, size: int, duration_ms: int}
     */
    public function prepareLiveClip(UploadedFile $file, int $clipIndex): array
    {
        $inputPath = $file->getRealPath();

        if (! is_string($inputPath) || ! is_file($inputPath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $directory = storage_path('app/private/audio-upload-chunks/'.uniqid('live-', true));
        File::ensureDirectoryExists($directory);

        $outputPath = $directory.DIRECTORY_SEPARATOR.sprintf('live_%05d.wav', max(1, $clipIndex));

        $this->runProcess([
            $this->processes->ffmpegPath(),
            '-y',
            '-i',
            $inputPath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ]);

        if (! is_file($outputPath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        return [
            'directory' => $directory,
            'path' => $outputPath,
            'name' => basename($outputPath),
            'mime_type' => 'audio/wav',
            'size' => filesize($outputPath) ?: 0,
            'duration_ms' => $this->durations->milliseconds($outputPath),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSections(int $durationMs, int $chunkSeconds): array
    {
        return $this->sections->buildSections($durationMs, $chunkSeconds);
    }

    public function cleanup(string $directory): void
    {
        if (str_starts_with($directory, storage_path('app/private/audio-upload-chunks')) && File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Delete only generated WAV files after one uploaded section reaches a
     * confirmed terminal success. The original source and session metadata stay
     * available for remaining sections and retries.
     */
    public function cleanupProcessedFiles(array ...$audioFiles): void
    {
        $sessionRoot = realpath(storage_path('app/private/audio-upload-sessions'));

        if (! is_string($sessionRoot)) {
            return;
        }

        $sessionPrefix = rtrim($sessionRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        foreach ($audioFiles as $audio) {
            $path = realpath((string) ($audio['path'] ?? ''));

            if (! is_string($path)
                || ! str_starts_with($path, $sessionPrefix)
                || preg_match('/^chunk_\d+(?:(?:-speech)?\.wav|(?:-speech)?-online\.ogg)$/i', basename($path)) !== 1) {
                continue;
            }

            File::delete($path);
        }
    }

    /** Delete a completed upload session without touching local-path source files. */
    public function cleanupSession(string $sessionId): void
    {
        $this->sessions->cleanup($sessionId);
    }

    public function sessionAvailable(string $sessionId): bool
    {
        return $this->sessions->available($sessionId);
    }

    private function runProcess(array $command): Process
    {
        return $this->processes->run($command, ServiceUserMessage::audioPrepareFailed());
    }

}
