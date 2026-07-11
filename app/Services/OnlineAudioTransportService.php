<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class OnlineAudioTransportService
{
    /** @return array{path: string, name: string, mime_type: string, size: int, duration_ms: int} */
    public function fromPreparedWav(array $audio): array
    {
        $input = (string) ($audio['path'] ?? '');

        if (! is_file($input)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $output = dirname($input).DIRECTORY_SEPARATOR.pathinfo($input, PATHINFO_FILENAME).'-online.ogg';
        $process = new Process([
            base_path('ffmpeg/bin/ffmpeg.exe'), '-y', '-i', $input, '-vn',
            '-ac', '1', '-ar', '16000', '-c:a', 'libopus', '-b:a', '32k',
            '-vbr', 'on', '-application', 'voip', $output,
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($output)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        return [
            'path' => $output,
            'name' => basename($output),
            'mime_type' => 'audio/ogg',
            'size' => filesize($output) ?: 0,
            'duration_ms' => (int) ($audio['duration_ms'] ?? 0),
        ];
    }
}
