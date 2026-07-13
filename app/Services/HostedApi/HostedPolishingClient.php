<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Exceptions\TranscriptPolisherException;
use App\Services\AppSettingsService;
use App\Services\ServiceUserMessage;
use App\Services\TrustedHttpClient;
use Illuminate\Http\Client\ConnectionException;

class HostedPolishingClient
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly HostedApiClient $api,
        private readonly HostedApiErrorMapper $errors,
        private readonly TrustedHttpClient $http,
    ) {}

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string|null, model: string|null}
     */
    public function polish(string $text, array $timestamps = [], array $options = []): array
    {
        $text = trim($text);
        $polisher = $this->polisherSelection();

        if ($text === '') {
            return [
                'text' => '',
                'timestamps' => [],
                'provider' => $polisher['provider'],
                'model' => $polisher['model'],
            ];
        }

        $rawResponse = $this->postJson('/polish', [
            'text' => $text,
            'timestamps' => $timestamps,
            'instruction' => trim((string) ($options['instructions'] ?? '')),
        ]);
        $response = $this->errors->responseData($rawResponse);
        $polishedText = (string) ($response['text'] ?? '');

        if (trim($polishedText) === '') {
            throw new TranscriptPolisherException(
                $this->errors->messageFromPayload($rawResponse)
                    ?? 'The transcription server returned a successful response without polished text.'
            );
        }

        return [
            'text' => $polishedText,
            'timestamps' => is_array($response['timestamps'] ?? null) ? array_values(array_filter($response['timestamps'], 'is_array')) : [],
            'provider' => $this->errors->nullableString($response['provider'] ?? $polisher['provider']),
            'model' => $this->errors->nullableString($response['model'] ?? $polisher['model']),
        ];
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, provider: string|null, model: string|null}
     */
    public function polishChunks(array $chunks, array $options = []): array
    {
        $polisher = $this->polisherSelection();
        $payloadChunks = array_values(array_map(
            fn (array $chunk): array => [
                'audio_chunk_id' => (int) $chunk['id'],
                'clip_index' => $chunk['clip_index'] ?? null,
                'range_label' => $chunk['range_label'] ?? null,
                'text' => trim((string) ($chunk['text'] ?? '')),
                'timestamps' => array_values(array_filter($chunk['timestamps'] ?? [], 'is_array')),
            ],
            $chunks,
        ));

        if ($payloadChunks === [] || collect($payloadChunks)->every(fn (array $chunk): bool => $chunk['text'] === '')) {
            return [
                'chunks' => array_map(
                    fn (array $chunk): array => [
                        'audio_chunk_id' => (int) $chunk['audio_chunk_id'],
                        'text' => '',
                        'timestamps' => [],
                    ],
                    $payloadChunks,
                ),
                'provider' => $polisher['provider'],
                'model' => $polisher['model'],
            ];
        }

        $rawResponse = $this->postJson('/polish', [
            'chunks' => $payloadChunks,
            'instruction' => trim((string) ($options['instructions'] ?? '')),
        ]);
        $response = $this->errors->responseData($rawResponse);
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
                    'timestamps' => is_array($chunk['timestamps'] ?? null) ? array_values(array_filter($chunk['timestamps'], 'is_array')) : [],
                ],
                $responseChunks,
            )),
            'provider' => $this->errors->nullableString($response['provider'] ?? $polisher['provider']),
            'model' => $this->errors->nullableString($response['model'] ?? $polisher['model']),
        ];
    }

    private function postJson(string $path, array $payload): array
    {
        $url = $this->api->url($path);

        try {
            $response = $this->api->request()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);
        } catch (SpeechToTextException $exception) {
            throw new TranscriptPolisherException($exception->getMessage(), 0, $exception);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure('Hosted transcript polishing connection failed.', $url, $exception);
            throw new TranscriptPolisherException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            $this->http->logHttpFailure('Hosted transcript polishing request failed.', $url, $response);
            throw new TranscriptPolisherException(
                $this->errors->messageForResponse($response, ServiceUserMessage::cleanerFailed()),
                $response->status(),
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new TranscriptPolisherException('The transcription server returned invalid JSON.');
        }

        $failed = ($decoded['success'] ?? null) === false
            || ($decoded['ok'] ?? null) === false
            || in_array(strtolower((string) ($decoded['status'] ?? '')), ['error', 'failed'], true);

        if ($failed) {
            throw new TranscriptPolisherException(
                $this->errors->messageFromPayload($decoded) ?? ServiceUserMessage::cleanerFailed()
            );
        }

        return $decoded;
    }

    /**
     * @return array{provider: string|null, model: string|null}
     */
    private function polisherSelection(): array
    {
        $providers = $this->settings->licenseStatus()['providers']['polishing'] ?? [];

        if (! is_array($providers)) {
            return [
                'provider' => null,
                'model' => null,
            ];
        }

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            if (! ($provider['configured'] ?? false) || ! ($provider['enabled'] ?? false) || ! ($provider['connected'] ?? false)) {
                continue;
            }

            $models = is_array($provider['models'] ?? null) ? $provider['models'] : [];

            return [
                'provider' => $this->errors->nullableString($provider['provider'] ?? null),
                'model' => $this->errors->nullableString($models[0]['id'] ?? null),
            ];
        }

        return [
            'provider' => null,
            'model' => null,
        ];
    }
}
