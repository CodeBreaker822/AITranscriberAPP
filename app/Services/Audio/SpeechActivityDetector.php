<?php

namespace App\Services\Audio;

interface SpeechActivityDetector
{
    /**
     * @return array{has_speech: bool, duration_ms: int, speech_ms: int, segments: array<int, array{start_ms: int, end_ms: int, start_seconds: float, end_seconds: float}>, detector?: string, bypassed?: bool}
     */
    public function detect(string $audioPath): array;
}
