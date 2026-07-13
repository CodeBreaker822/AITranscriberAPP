<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\AppSettingsService;
use App\Services\ServiceUserMessage;
use App\Services\TrustedHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class HostedTranscriptionClient
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly HostedApiClient $api,
        private readonly HostedTranscriptionJobClient $jobs,
        private readonly TrustedHttpClient $http,
    ) {}

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

        $this->assertUploadBytesAreAllowed([$file]);
        $stream = $this->openAudioStream($file);
        $url = $this->api->url('/transcribe');

        try {
            $response = $this->api->request()
                ->attach('audio', $stream, $file['name'])
                ->post($url, [
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
                $url,
                $exception,
                [
                    'provider' => $selection['provider'],
                    'model' => $selection['model'],
                    'language_code' => $selection['language'],
                    'file_name' => $file['name'],
                ],
            );
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        } finally {
            fclose($stream);
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

        $payload = $this->jobs->resolveAsyncTranscriptionPayload($response->json() ?? []);

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
        $this->assertUploadBytesAreAllowed($files);
        $request = $this->api->request();
        $streams = [];
        $url = $this->api->url('/transcribe');

        try {
            foreach ($files as $file) {
                $stream = $this->openAudioStream($file);
                $streams[] = $stream;
                $request = $request->attach('audio[]', $stream, $file['name']);
            }

            $response = $request->post($url, [
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
                $url,
                $exception,
                [
                    'provider' => $selection['provider'],
                    'model' => $selection['model'],
                    'language_code' => $selection['language'],
                    'clip_count' => count($clips),
                ],
            );
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        } finally {
            $this->closeAudioStreams($streams);
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

        $payload = $this->jobs->resolveAsyncTranscriptionPayload($response->json() ?? []);
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

        return max(0, (int) $clipEndMs - (int) $clipStartMs) > $maxDurationMs;
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
     * @param  array<int, array{path: string, name: string, size: int}>  $files
     */
    private function assertUploadBytesAreAllowed(array $files): void
    {
        $maxBytes = $this->settings->transcribeMaxUploadBytes();

        if ($maxBytes === null) {
            return;
        }

        $totalBytes = array_sum(array_map(
            fn (array $file): int => max(0, (int) $file['size']),
            $files,
        ));

        if ($totalBytes > $maxBytes) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }
    }

    /**
     * @return array{path: string, name: string, size: int}
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
                'size' => max(0, (int) $audio->getSize()),
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
                'size' => max(0, (int) filesize($path)),
            ];
        }

        if (! is_file($audio)) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
            'size' => max(0, (int) filesize($audio)),
        ];
    }

    /**
     * @param  array{path: string, name: string, size: int}  $file
     * @return resource
     */
    private function openAudioStream(array $file)
    {
        $stream = fopen($file['path'], 'rb');

        if ($stream === false) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return $stream;
    }

    /**
     * @param  array<int, resource>  $streams
     */
    private function closeAudioStreams(array $streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
