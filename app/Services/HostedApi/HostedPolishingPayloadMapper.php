<?php

namespace App\Services\HostedApi;

use App\Exceptions\TranscriptPolisherException;

class HostedPolishingPayloadMapper
{
    public function __construct(private readonly HostedApiErrorMapper $errors)
    {
    }

    /** @return array{provider: string|null, model: string|null} */
    public function selection(array $licenseStatus): array
    {
        $providers = $licenseStatus['providers']['polishing'] ?? [];

        if (! is_array($providers)) {
            return $this->emptySelection();
        }

        foreach ($providers as $provider) {
            if (! is_array($provider)
                || ! ($provider['configured'] ?? false)
                || ! ($provider['enabled'] ?? false)
                || ! ($provider['connected'] ?? false)) {
                continue;
            }

            $models = is_array($provider['models'] ?? null) ? $provider['models'] : [];

            return [
                'provider' => $this->errors->nullableString($provider['provider'] ?? null),
                'model' => $this->errors->nullableString($models[0]['id'] ?? null),
            ];
        }

        return $this->emptySelection();
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string|null, model: string|null}
     */
    public function single(array $rawResponse, array $response, array $selection): array
    {
        $polishedText = (string) ($response['text'] ?? '');

        if (trim($polishedText) === '') {
            throw new TranscriptPolisherException(
                $this->errors->messageFromPayload($rawResponse)
                    ?? 'The transcription server returned a successful response without polished text.'
            );
        }

        return [
            'text' => $polishedText,
            'timestamps' => $this->timestamps($response['timestamps'] ?? null),
            'provider' => $this->errors->nullableString($response['provider'] ?? $selection['provider']),
            'model' => $this->errors->nullableString($response['model'] ?? $selection['model']),
        ];
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array<int, array<string, mixed>>
     */
    public function requestChunks(array $chunks): array
    {
        return array_values(array_map(
            fn (array $chunk): array => [
                'audio_chunk_id' => (int) $chunk['id'],
                'clip_index' => $chunk['clip_index'] ?? null,
                'range_label' => $chunk['range_label'] ?? null,
                'text' => trim((string) ($chunk['text'] ?? '')),
                'timestamps' => $this->timestamps($chunk['timestamps'] ?? null),
            ],
            $chunks,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloadChunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, provider: string|null, model: string|null}
     */
    public function emptyChunks(array $payloadChunks, array $selection): array
    {
        return [
            'chunks' => array_map(
                fn (array $chunk): array => [
                    'audio_chunk_id' => (int) $chunk['audio_chunk_id'],
                    'text' => '',
                    'timestamps' => [],
                ],
                $payloadChunks,
            ),
            'provider' => $selection['provider'],
            'model' => $selection['model'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloadChunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, provider: string|null, model: string|null}
     */
    public function chunks(array $rawResponse, array $response, array $payloadChunks, array $selection): array
    {
        $responseChunks = array_values(array_filter($response['chunks'] ?? [], 'is_array'));
        $expectedIds = collect($payloadChunks)
            ->filter(fn (array $chunk): bool => $chunk['text'] !== '')
            ->map(fn (array $chunk): int => (int) $chunk['audio_chunk_id'])
            ->values();
        $validChunks = collect($responseChunks)
            ->filter(fn (array $chunk): bool => (int) ($chunk['audio_chunk_id'] ?? 0) > 0
                && trim((string) ($chunk['text'] ?? '')) !== '')
            ->keyBy(fn (array $chunk): int => (int) $chunk['audio_chunk_id']);

        if ($expectedIds->contains(fn (int $id): bool => ! $validChunks->has($id))) {
            throw new TranscriptPolisherException(
                $this->errors->messageFromPayload($rawResponse)
                    ?? 'The transcription server returned an incomplete or empty polished transcript.'
            );
        }

        return [
            'chunks' => array_values(array_map(
                fn (array $chunk): array => [
                    'audio_chunk_id' => (int) ($chunk['audio_chunk_id'] ?? 0),
                    'text' => (string) ($chunk['text'] ?? ''),
                    'timestamps' => $this->timestamps($chunk['timestamps'] ?? null),
                ],
                $responseChunks,
            )),
            'provider' => $this->errors->nullableString($response['provider'] ?? $selection['provider']),
            'model' => $this->errors->nullableString($response['model'] ?? $selection['model']),
        ];
    }

    /** @param array<int, array<string, mixed>> $chunks */
    public function allChunksEmpty(array $chunks): bool
    {
        return $chunks === [] || collect($chunks)->every(fn (array $chunk): bool => $chunk['text'] === '');
    }

    /** @return array{provider: null, model: null} */
    private function emptySelection(): array
    {
        return [
            'provider' => null,
            'model' => null,
        ];
    }

    private function timestamps(mixed $timestamps): array
    {
        return is_array($timestamps)
            ? array_values(array_filter($timestamps, 'is_array'))
            : [];
    }
}
