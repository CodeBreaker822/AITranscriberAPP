<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Exceptions\TranscriptPolisherException;
use App\Services\Config\AppSettingsService;
use App\Services\Support\ServiceUserMessage;
use App\Services\Http\TrustedHttpClient;
use Illuminate\Http\Client\ConnectionException;

class HostedPolishingClient
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly HostedApiClient $api,
        private readonly HostedApiErrorMapper $errors,
        private readonly TrustedHttpClient $http,
        private readonly HostedPolishingPayloadMapper $payloads,
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

        return $this->payloads->single($rawResponse, $response, $polisher);
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, provider: string|null, model: string|null}
     */
    public function polishChunks(array $chunks, array $options = []): array
    {
        $polisher = $this->polisherSelection();
        $payloadChunks = $this->payloads->requestChunks($chunks);

        if ($this->payloads->allChunksEmpty($payloadChunks)) {
            return $this->payloads->emptyChunks($payloadChunks, $polisher);
        }

        $rawResponse = $this->postJson('/polish', [
            'chunks' => $payloadChunks,
            'instruction' => trim((string) ($options['instructions'] ?? '')),
        ]);
        $response = $this->errors->responseData($rawResponse);

        return $this->payloads->chunks($rawResponse, $response, $payloadChunks, $polisher);
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
        return $this->payloads->selection($this->settings->licenseStatus());
    }
}
