<?php

namespace App\Services\Speakers;

class SpeakerDiarizationPolicy
{
    public function driver(?string $driver = null): string
    {
        return strtolower(trim((string) ($driver ?: config('services.speaker_diarization.driver', 'local'))));
    }

    public function enabled(?string $driver = null): bool
    {
        return ! in_array($this->driver($driver), ['off', 'disabled', 'none', 'passthrough'], true);
    }
}
