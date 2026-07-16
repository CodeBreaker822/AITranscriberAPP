<?php

namespace App\Services\Audio;

use App\Services\Support\ServiceUserMessage;

class AudioDurationProbe
{
    public function __construct(private readonly AudioProcessRunner $processes) {}

    public function seconds(string $path): float
    {
        $process = $this->processes->run([
            $this->processes->ffprobePath(),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ], ServiceUserMessage::audioPrepareFailed());

        return (float) trim($process->getOutput());
    }

    public function milliseconds(string $path): int
    {
        return max(1, (int) round($this->seconds($path) * 1000));
    }
}
