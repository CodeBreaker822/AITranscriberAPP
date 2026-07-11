<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

class AudioFileChunkerService
{
    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createSession(UploadedFile $file): array
    {
        $sessionId = (string) Str::uuid();
        $workDirectory = $this->sessionDirectory($sessionId);
        File::ensureDirectoryExists($workDirectory);

        $sourcePath = $workDirectory.DIRECTORY_SEPARATOR.'source.'.$this->extension($file);
        $file->move($workDirectory, basename($sourcePath));

        $durationMs = max(1, (int) round($this->probeDurationSeconds($sourcePath) * 1000));

        file_put_contents($workDirectory.DIRECTORY_SEPARATOR.'session.json', json_encode([
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
            'created_at' => now()->toISOString(),
        ]));

        return [
            'session_id' => $sessionId,
            'directory' => $workDirectory,
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createSessionFromPath(string $sourcePath): array
    {
        $sourcePath = trim($sourcePath);

        if ($sourcePath === '' || ! is_file($sourcePath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $sourceFile = new SplFileInfo($sourcePath);
        $realPath = $sourceFile->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $sessionId = (string) Str::uuid();
        $workDirectory = $this->sessionDirectory($sessionId);
        File::ensureDirectoryExists($workDirectory);

        $durationMs = max(1, (int) round($this->probeDurationSeconds($realPath) * 1000));

        file_put_contents($workDirectory.DIRECTORY_SEPARATOR.'session.json', json_encode([
            'source_path' => $realPath,
            'duration_ms' => $durationMs,
            'created_at' => now()->toISOString(),
            'source_mode' => 'local_path',
        ]));

        return [
            'session_id' => $sessionId,
            'directory' => $workDirectory,
            'source_path' => $realPath,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @return array{path: string, name: string, mime_type: string, size: int, duration_ms: int}
     */
    public function extractSegment(string $sessionId, int $clipIndex, int $startMs, int $durationMs): array
    {
        $session = $this->readSession($sessionId);
        $directory = $this->sessionDirectory($sessionId);
        $outputPath = $directory.DIRECTORY_SEPARATOR.sprintf('chunk_%05d.wav', $clipIndex);

        $this->runProcess([
            $this->ffmpegPath(),
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

        $preparedDurationMs = max(1, (int) round($this->probeDurationSeconds($outputPath) * 1000));

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

        $this->readSession($sessionId);
        $path = $this->sessionDirectory($sessionId).DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        return [
            'path' => $path,
            'name' => $name,
            'mime_type' => 'audio/wav',
            'size' => filesize($path) ?: 0,
            'duration_ms' => max(1, (int) round($this->probeDurationSeconds($path) * 1000)),
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
            $this->ffmpegPath(),
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
            'duration_ms' => max(1, (int) round($this->probeDurationSeconds($outputPath) * 1000)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSections(int $durationMs, int $chunkSeconds): array
    {
        $chunkMs = max(1, $chunkSeconds) * 1000;
        $count = max(1, (int) ceil($durationMs / $chunkMs));

        return array_map(function (int $index) use ($chunkMs, $durationMs): array {
            $startMs = $index * $chunkMs;
            $endMs = min(($index + 1) * $chunkMs, $durationMs);

            return [
                'index' => $index + 1,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'duration_ms' => max(1, $endMs - $startMs),
                'range_label' => $this->formatRange($startMs, $endMs),
            ];
        }, range(0, $count - 1));
    }

    /**
     * @return array{directory: string, segments: array<int, array<string, mixed>>}
     */
    public function split(UploadedFile $file, int $chunkSeconds = 60): array
    {
        $chunkSeconds = max(1, $chunkSeconds);
        $workDirectory = storage_path('app/private/audio-upload-chunks/'.uniqid('upload-', true));
        File::ensureDirectoryExists($workDirectory);

        $sourcePath = $workDirectory.DIRECTORY_SEPARATOR.'source.'.$this->extension($file);
        $file->move($workDirectory, basename($sourcePath));

        $outputPattern = $workDirectory.DIRECTORY_SEPARATOR.'chunk_%05d.wav';

        $this->runProcess([
            $this->ffmpegPath(),
            '-y',
            '-i',
            $sourcePath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            '-f',
            'segment',
            '-segment_time',
            (string) $chunkSeconds,
            '-reset_timestamps',
            '1',
            $outputPattern,
        ]);

        $files = collect(File::files($workDirectory))
            ->filter(fn ($path) => str_starts_with($path->getFilename(), 'chunk_') && $path->getExtension() === 'wav')
            ->sortBy(fn ($path) => $path->getFilename())
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        $segments = [];
        $cursorMs = 0;

        foreach ($files as $index => $chunkFile) {
            $durationMs = max(1, (int) round($this->probeDurationSeconds($chunkFile->getPathname()) * 1000));
            $startMs = $cursorMs;
            $endMs = $startMs + $durationMs;

            $segments[] = [
                'index' => $index + 1,
                'path' => $chunkFile->getPathname(),
                'name' => $chunkFile->getFilename(),
                'mime_type' => 'audio/wav',
                'size' => $chunkFile->getSize(),
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'duration_ms' => $durationMs,
                'range_label' => $this->formatRange($startMs, $endMs),
            ];

            $cursorMs = $endMs;
        }

        return [
            'directory' => $workDirectory,
            'segments' => $segments,
        ];
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
        $sessionRoot = realpath(storage_path('app/private/audio-upload-sessions'));
        $directory = realpath($this->sessionDirectory($sessionId));

        if (! is_string($sessionRoot) || ! is_string($directory)) {
            return;
        }

        $sessionPrefix = rtrim($sessionRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($directory, $sessionPrefix) && $directory !== $sessionRoot) {
            File::deleteDirectory($directory);
        }
    }

    public function sessionAvailable(string $sessionId): bool
    {
        try {
            $this->readSession($sessionId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function extension(UploadedFile $file): string
    {
        return $file->getClientOriginalExtension() ?: 'audio';
    }

    /**
     * @return array{source_path: string, duration_ms: int, created_at?: string}
     */
    private function readSession(string $sessionId): array
    {
        $path = $this->sessionDirectory($sessionId).DIRECTORY_SEPARATOR.'session.json';

        if (! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::uploadSessionExpired());
        }

        $session = json_decode((string) file_get_contents($path), true);

        if (! is_array($session) || empty($session['source_path']) || ! is_file($session['source_path'])) {
            throw new RuntimeException(ServiceUserMessage::uploadSessionExpired());
        }

        return $session;
    }

    private function sessionDirectory(string $sessionId): string
    {
        return storage_path('app/private/audio-upload-sessions/'.$sessionId);
    }

    private function probeDurationSeconds(string $path): float
    {
        $process = $this->runProcess([
            $this->ffprobePath(),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        return (float) trim($process->getOutput());
    }

    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Audio processing command failed.', [
                'executable' => basename((string) ($command[0] ?? '')),
                'exit_code' => $process->getExitCode(),
                'stderr' => trim($process->getErrorOutput()),
            ]);

            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        return $process;
    }

    private function ffmpegPath(): string
    {
        $bundled = base_path('ffmpeg/bin/ffmpeg.exe');

        return $bundled;
    }

    private function ffprobePath(): string
    {
        $bundled = base_path('ffmpeg/bin/ffprobe.exe');

        return $bundled;
    }

    private function formatRange(int $startMs, int $endMs): string
    {
        return $this->formatClock($startMs).'-'.$this->formatClock($endMs);
    }

    private function formatClock(int $milliseconds): string
    {
        $totalSeconds = max(0, intdiv($milliseconds, 1000));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
