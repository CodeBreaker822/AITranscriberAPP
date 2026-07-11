<?php

namespace App\Services;

use App\Exceptions\TranscriptPolisherException;
use App\Exceptions\SpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class HostedTranscriptionApiService
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly TrustedHttpClient $http,
    ) {
    }

    public function licenseStatus(?string $licenseKey = null): array
    {
        try {
            $response = $this->request($licenseKey)->get($this->url('/license/status'));
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure(
                'Hosted license status connection failed.',
                $this->url('/license/status'),
                $exception,
            );
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            $this->http->logHttpFailure(
                'Hosted license status request failed.',
                $this->url('/license/status'),
                $response,
            );
            throw new SpeechToTextException($this->messageForResponse($response, 'License status could not be checked.'));
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    public function serverIsReachable(): bool
    {
        try {
            Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->withOptions($this->http->options())
                ->get($this->settings->apiBaseUrl());

            // Any HTTP response means the hosted server is reachable. This quiet
            // probe intentionally sends no license key and inspects no content.
            return true;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function downloadUpdateArchive(?string $downloadUrl = null): Response
    {
        $url = $this->updateArchiveUrl($downloadUrl);

        try {
            return $this->request()
                ->timeout(600)
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure(
                'Hosted update download connection failed.',
                $url,
                $exception,
            );
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('update server'), 0, $exception);
        }
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider?: string, model?: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->resolveAudioFile($audio);
        $selection = $this->settings->transcriptionSelection($options['language_code'] ?? null);

        if ($selection['provider'] === '' || $selection['model'] === '') {
            throw new SpeechToTextException('Save and test your license key in Settings before transcribing audio.');
        }

        if ($this->audioExceedsServerBatchLimit($options)) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }

        $contents = file_get_contents($file['path']);

        if ($contents === false) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->request()
                ->attach('audio', $contents, $file['name'])
                ->post($this->url('/transcribe'), [
                    'provider' => $selection['provider'],
                    'model' => $selection['model'],
                    'language_code' => $selection['language'],
                    'clip_index' => $options['clip_index'] ?? null,
                    'clip_start_ms' => $options['clip_start_ms'] ?? null,
                    'clip_end_ms' => $options['clip_end_ms'] ?? null,
                    'response_mode' => 'async',
                ]);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure(
                'Hosted transcription connection failed.',
                $this->url('/transcribe'),
                $exception,
                [
                    'provider' => $selection['provider'],
                    'model' => $selection['model'],
                    'language_code' => $selection['language'],
                    'file_name' => $file['name'],
                ],
            );
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            Log::error('Hosted transcription request failed.', [
                'status' => $response->status(),
                'provider' => $selection['provider'],
                'model' => $selection['model'],
                'language_code' => $selection['language'],
                'file_name' => $file['name'],
                'response' => $response->json() ?? $response->body(),
            ]);

            throw new SpeechToTextException(ServiceUserMessage::transcriptionFailed('Transcription server'), $response->status());
        }

        $payload = $this->resolveAsyncTranscriptionPayload($response->json() ?? []);

        return [
            'text' => (string) ($payload['text'] ?? ''),
            'timestamps' => is_array($payload['timestamps'] ?? null) ? array_values(array_filter($payload['timestamps'], 'is_array')) : [],
            'provider' => $payload['provider'] ?? $selection['provider'],
            'model' => $payload['model'] ?? $selection['model'],
        ];
    }

    /**
     * @param  array<int, array{audio: UploadedFile|string|SplFileInfo, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int}>  $clips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int, queue_index?: int, provider?: string|null, model?: string|null}>
     */
    public function transcribeBatch(array $clips, array $options = []): array
    {
        $clips = array_values($clips);

        if ($clips === []) {
            return [];
        }

        $selection = $this->settings->transcriptionSelection($options['language_code'] ?? null);

        if ($selection['provider'] === '' || $selection['model'] === '') {
            throw new SpeechToTextException('Save and test your license key in Settings before transcribing audio.');
        }

        $this->assertBatchIsAllowed($clips);
        $files = array_map(fn (array $clip): array => $this->resolveAudioFile($clip['audio']), $clips);
        $request = $this->request();

        foreach ($files as $file) {
            $contents = file_get_contents($file['path']);

            if ($contents === false) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            $request = $request->attach('audio[]', $contents, $file['name']);
        }

        try {
            $response = $request->post($this->url('/transcribe'), [
                'provider' => $selection['provider'],
                'model' => $selection['model'],
                'language_code' => array_fill(0, count($clips), $selection['language']),
                'clip_index' => array_map(fn (array $clip): mixed => $clip['clip_index'] ?? null, $clips),
                'clip_start_ms' => array_map(fn (array $clip): mixed => $clip['clip_start_ms'] ?? null, $clips),
                'clip_end_ms' => array_map(fn (array $clip): mixed => $clip['clip_end_ms'] ?? null, $clips),
                'response_mode' => 'async',
            ]);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure(
                'Hosted transcription batch connection failed.',
                $this->url('/transcribe'),
                $exception,
                [
                    'provider' => $selection['provider'],
                    'model' => $selection['model'],
                    'language_code' => $selection['language'],
                    'clip_count' => count($clips),
                ],
            );
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            Log::error('Hosted transcription batch request failed.', [
                'status' => $response->status(),
                'provider' => $selection['provider'],
                'model' => $selection['model'],
                'language_code' => $selection['language'],
                'clip_count' => count($clips),
                'response' => $response->json() ?? $response->body(),
            ]);

            $payload = $response->json();
            $message = is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : ServiceUserMessage::transcriptionFailed('Transcription server');

            throw new SpeechToTextException($message, $response->status());
        }

        $payload = $this->resolveAsyncTranscriptionPayload($response->json() ?? []);
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
                'timestamps' => is_array($clip['timestamps'] ?? null) ? array_values(array_filter($clip['timestamps'], 'is_array')) : [],
                'clip_index' => isset($clip['clip_index']) ? (int) $clip['clip_index'] : null,
                'clip_start_ms' => isset($clip['clip_start_ms']) ? (int) $clip['clip_start_ms'] : null,
                'clip_end_ms' => isset($clip['clip_end_ms']) ? (int) $clip['clip_end_ms'] : null,
                'queue_index' => isset($clip['queue_index']) ? (int) $clip['queue_index'] : null,
                'provider' => $clip['provider'] ?? $selection['provider'],
                'model' => $clip['model'] ?? $selection['model'],
            ];
        }, $responseClips);
    }

    private function audioExceedsServerBatchLimit(array $options): bool
    {
        $maxDurationMs = $this->settings->transcribeMaxBatchDurationMs();

        if ($maxDurationMs === null) {
            return false;
        }

        $clipStartMs = $options['clip_start_ms'] ?? null;
        $clipEndMs = $options['clip_end_ms'] ?? null;

        if (! is_numeric($clipStartMs) || ! is_numeric($clipEndMs)) {
            return false;
        }

        $clipStartMs = (int) $clipStartMs;
        $clipEndMs = (int) $clipEndMs;

        return $clipEndMs > $maxDurationMs || ($clipEndMs - $clipStartMs) > $maxDurationMs;
    }

    private function assertBatchIsAllowed(array $clips): void
    {
        $maxClips = $this->settings->transcribeMaxBatchClips();

        if ($maxClips !== null && count($clips) > $maxClips) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }

        $maxDurationMs = $this->settings->transcribeMaxBatchDurationMs();

        if ($maxDurationMs === null) {
            return;
        }

        $durationMs = 0;

        foreach ($clips as $clip) {
            $clipStartMs = $clip['clip_start_ms'] ?? null;
            $clipEndMs = $clip['clip_end_ms'] ?? null;

            if (! is_numeric($clipStartMs) || ! is_numeric($clipEndMs)) {
                continue;
            }

            $clipDurationMs = max(0, (int) $clipEndMs - (int) $clipStartMs);

            if ($clipDurationMs > $maxDurationMs) {
                throw new SpeechToTextException('Audio is too big.', 422);
            }

            $durationMs += $clipDurationMs;
        }

        if ($durationMs > $maxDurationMs) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveAsyncTranscriptionPayload(array $payload): array
    {
        $jobId = trim((string) ($payload['job_id'] ?? ''));

        if ($jobId === '') {
            return $payload;
        }

        $deadline = microtime(true) + (int) config(
            'services.transcription_api.async_timeout',
            (int) config('services.transcription_api.timeout', 1800),
        );
        $statusUrl = $this->url('/transcribe/jobs/'.rawurlencode($jobId));

        do {
            try {
                $response = $this->request()
                    ->timeout(30)
                    ->get($statusUrl);
            } catch (ConnectionException $exception) {
                $this->http->logConnectionFailure(
                    'Hosted transcription job status connection failed.',
                    $statusUrl,
                    $exception,
                    ['job_id' => $jobId],
                );
                throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
            }

            if ($response->failed()) {
                Log::error('Hosted transcription job status request failed.', [
                    'status' => $response->status(),
                    'job_id' => $jobId,
                    'response' => $response->json() ?? $response->body(),
                ]);

                throw new SpeechToTextException(
                    $this->messageForResponse($response, ServiceUserMessage::transcriptionFailed('Transcription server')),
                    $response->status(),
                );
            }

            $jobPayload = $response->json() ?? [];
            $status = strtolower((string) ($jobPayload['status'] ?? ''));

            if (in_array($status, ['completed', 'complete', 'succeeded', 'success'], true)) {
                $result = $jobPayload['result'] ?? $jobPayload['data'] ?? $jobPayload;

                return is_array($result) ? $result : [];
            }

            if (in_array($status, ['failed', 'cancelled', 'canceled', 'timed_out', 'timeout'], true)) {
                $message = $this->messageFromPayload($jobPayload)
                    ?? ServiceUserMessage::transcriptionFailed('Transcription server');

                throw new SpeechToTextException($message, (int) ($jobPayload['status_code'] ?? 0));
            }

            usleep(2_000_000);
        } while (microtime(true) < $deadline);

        throw new SpeechToTextException('Transcription server timed out while waiting for the async job.');
    }

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
        $response = $this->responseData($rawResponse);
        $polishedText = (string) ($response['text'] ?? '');

        if (trim($polishedText) === '') {
            throw new TranscriptPolisherException(
                $this->messageFromPayload($rawResponse)
                    ?? 'The transcription server returned a successful response without polished text.'
            );
        }

        return [
            'text' => $polishedText,
            'timestamps' => is_array($response['timestamps'] ?? null) ? array_values(array_filter($response['timestamps'], 'is_array')) : [],
            'provider' => $this->nullableString($response['provider'] ?? $polisher['provider']),
            'model' => $this->nullableString($response['model'] ?? $polisher['model']),
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
        $response = $this->responseData($rawResponse);
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
                $this->messageFromPayload($rawResponse)
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
            'provider' => $this->nullableString($response['provider'] ?? $polisher['provider']),
            'model' => $this->nullableString($response['model'] ?? $polisher['model']),
        ];
    }

    private function postJson(string $path, array $payload): array
    {
        try {
            $response = $this->request()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->url($path), $payload);
        } catch (SpeechToTextException $exception) {
            throw new TranscriptPolisherException($exception->getMessage(), 0, $exception);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure(
                'Hosted transcript polishing connection failed.',
                $this->url($path),
                $exception,
            );
            throw new TranscriptPolisherException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            $this->http->logHttpFailure(
                'Hosted transcript polishing request failed.',
                $this->url($path),
                $response,
            );
            throw new TranscriptPolisherException($this->messageForResponse($response, ServiceUserMessage::cleanerFailed()), $response->status());
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
                $this->messageFromPayload($decoded) ?? ServiceUserMessage::cleanerFailed()
            );
        }

        return $decoded;
    }

    private function responseData(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function messageFromPayload(array $payload): ?string
    {
        foreach (['message', 'error', 'detail'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (is_array($payload['data'] ?? null)) {
            return $this->messageFromPayload($payload['data']);
        }

        $errors = $payload['errors'] ?? null;

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $value = is_array($error) ? reset($error) : $error;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    private function request(?string $licenseKey = null)
    {
        $licenseKey = trim((string) ($licenseKey ?? $this->settings->licenseKey()));

        if ($licenseKey === '') {
            throw new SpeechToTextException('Add your license key in Settings before continuing.');
        }

        return Http::withToken($licenseKey)
            ->acceptJson()
            ->withOptions($this->http->options())
            ->timeout((int) config('services.transcription_api.timeout', 1800));
    }

    private function url(string $path): string
    {
        return $this->settings->apiBaseUrl().'/'.ltrim($path, '/');
    }

    private function updateArchiveUrl(?string $downloadUrl): string
    {
        $downloadUrl = trim((string) $downloadUrl);

        if ($downloadUrl === '') {
            return $this->url('/transcribe/update/zipfile');
        }

        if (preg_match('/^https?:\/\//i', $downloadUrl) === 1) {
            $baseUrl = $this->settings->apiBaseUrl();
            $baseParts = parse_url($baseUrl);
            $downloadParts = parse_url($downloadUrl);
            $sameScheme = strtolower((string) ($baseParts['scheme'] ?? '')) === strtolower((string) ($downloadParts['scheme'] ?? ''));
            $sameHost = strtolower((string) ($baseParts['host'] ?? '')) === strtolower((string) ($downloadParts['host'] ?? ''));
            $basePort = (string) ($baseParts['port'] ?? '');
            $downloadPort = (string) ($downloadParts['port'] ?? '');

            if ($sameScheme && $sameHost && $basePort === $downloadPort) {
                return $downloadUrl;
            }

            return $this->url('/transcribe/update/zipfile');
        }

        return $this->url($downloadUrl);
    }

    private function messageForResponse(Response $response, string $fallback): string
    {
        $payload = $response->json();

        if (is_array($payload) && ($message = $this->messageFromPayload($payload)) !== null) {
            return $message;
        }

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->json('retry_after') ?? 0);

            return $retryAfter > 0
                ? "License key is rate-limited. Try again in {$retryAfter} seconds."
                : 'License key is rate-limited. Please wait and try again.';
        }

        return $fallback;
    }

    /**
     * @return array{path: string, name: string}
     */
    private function resolveAudioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
            ];
        }

        if (! is_file($audio)) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
        ];
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
                'provider' => $this->nullableString($provider['provider'] ?? null),
                'model' => $this->nullableString($models[0]['id'] ?? null),
            ];
        }

        return [
            'provider' => null,
            'model' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
