<?php

namespace App\Services\HostedApi;

class HostedTranscriptionPayloadMapper
{
    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider?: string, model?: string}
     */
    public function single(array $payload, array $selection): array
    {
        return [
            'text' => (string) ($payload['text'] ?? ''),
            'timestamps' => $this->timestamps($payload['timestamps'] ?? null),
            'provider' => $payload['provider'] ?? $selection['provider'],
            'model' => $payload['model'] ?? $selection['model'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int, queue_index?: int, provider?: string|null, model?: string|null}>
     */
    public function batch(array $payload, array $clips, array $selection): array
    {
        $responseClips = is_array($payload['clips'] ?? null)
            ? array_values(array_filter($payload['clips'], 'is_array'))
            : [];

        if ($responseClips === []) {
            $responseClips[] = [
                'text' => (string) ($payload['text'] ?? ''),
                'timestamps' => is_array($payload['timestamps'] ?? null) ? $payload['timestamps'] : [],
                'clip_index' => $clips[0]['clip_index'] ?? null,
                'clip_start_ms' => $clips[0]['clip_start_ms'] ?? null,
                'clip_end_ms' => $clips[0]['clip_end_ms'] ?? null,
                'queue_index' => 0,
                'provider' => $payload['provider'] ?? $selection['provider'],
                'model' => $payload['model'] ?? $selection['model'],
            ];
        }

        return array_map(function (array $clip) use ($selection): array {
            return [
                'text' => (string) ($clip['text'] ?? ''),
                'timestamps' => $this->timestamps($clip['timestamps'] ?? null),
                'clip_index' => isset($clip['clip_index']) ? (int) $clip['clip_index'] : null,
                'clip_start_ms' => isset($clip['clip_start_ms']) ? (int) $clip['clip_start_ms'] : null,
                'clip_end_ms' => isset($clip['clip_end_ms']) ? (int) $clip['clip_end_ms'] : null,
                'queue_index' => isset($clip['queue_index']) ? (int) $clip['queue_index'] : null,
                'provider' => $clip['provider'] ?? $selection['provider'],
                'model' => $clip['model'] ?? $selection['model'],
            ];
        }, $responseClips);
    }

    private function timestamps(mixed $timestamps): array
    {
        return is_array($timestamps)
            ? array_values(array_filter($timestamps, 'is_array'))
            : [];
    }
}
