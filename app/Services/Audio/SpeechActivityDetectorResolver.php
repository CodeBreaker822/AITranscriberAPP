<?php

namespace App\Services\Audio;

class SpeechActivityDetectorResolver
{
    public function __construct(
        private readonly SileroVadService $silero,
        private readonly PassthroughSpeechActivityDetector $passthrough,
    ) {}

    public function detector(?string $driver = null): SpeechActivityDetector
    {
        $driver = strtolower(trim((string) ($driver ?: config('services.silero_vad.driver', 'silero'))));

        return match ($driver) {
            'off', 'disabled', 'none', 'passthrough' => $this->passthrough,
            default => $this->silero,
        };
    }

    public function enabled(?string $driver = null): bool
    {
        return $this->detector($driver) instanceof SileroVadService;
    }
}
