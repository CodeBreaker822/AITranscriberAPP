<?php

namespace App\Services\Audio;

use App\Services\Support\ServiceUserMessage;
use RuntimeException;

class PassthroughSpeechActivityDetector implements SpeechActivityDetector
{
    /**
     * @return array{has_speech: bool, duration_ms: int, speech_ms: int, segments: array<int, array{start_ms: int, end_ms: int, start_seconds: float, end_seconds: float}>, detector: string, bypassed: bool}
     */
    public function detect(string $audioPath): array
    {
        if (! is_file($audioPath)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'has_speech' => true,
            'duration_ms' => 0,
            'speech_ms' => 0,
            'segments' => [],
            'detector' => 'passthrough',
            'bypassed' => true,
        ];
    }
}
