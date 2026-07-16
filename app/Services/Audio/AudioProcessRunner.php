<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class AudioProcessRunner
{
    public function run(array $command, string $userMessage, ?int $timeout = null): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Audio processing command failed.', [
                'executable' => basename((string) ($command[0] ?? '')),
                'exit_code' => $process->getExitCode(),
                'stderr' => trim($process->getErrorOutput()),
            ]);

            throw new RuntimeException($userMessage);
        }

        return $process;
    }

    public function ffmpegPath(): string
    {
        return base_path('ffmpeg/bin/ffmpeg.exe');
    }

    public function ffprobePath(): string
    {
        return base_path('ffmpeg/bin/ffprobe.exe');
    }
}
