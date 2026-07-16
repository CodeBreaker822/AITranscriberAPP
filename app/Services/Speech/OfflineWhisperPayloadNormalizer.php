<?php

namespace App\Services\Speech;

use App\Exceptions\SpeechToTextException;

class OfflineWhisperPayloadNormalizer
{
    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string, model: string}
     */
    public function normalize(array $payload): array
    {
        if (is_string($payload['error'] ?? null) && trim($payload['error']) !== '') {
            throw new SpeechToTextException(trim($payload['error']));
        }

        $text = trim((string) ($payload['text'] ?? ''));

        return [
            'text' => $text,
            'timestamps' => is_array($payload['timestamps'] ?? null)
                ? array_values(array_filter($payload['timestamps'], 'is_array'))
                : [],
            'provider' => (string) ($payload['provider'] ?? 'whisper.cpp'),
            'model' => (string) ($payload['model'] ?? 'large-v3-turbo-q8_0'),
        ];
    }
}
