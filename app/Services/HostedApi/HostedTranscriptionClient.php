<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Config\AppSettingsService;
use App\Services\Support\ServiceUserMessage;
use App\Services\Http\TrustedHttpClient;
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
        private readonly HostedAudioFileResolver $files,
        private readonly HostedTranscriptionLimitGuard $limits,
        private readonly HostedTranscriptionPayloadMapper $payloads,
    ) {}

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider?: string, model?: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->files->resolve($audio);
        $selection = $this->settings->transcriptionSelection($options['language_code'] ?? null);

        if ($selection['provider'] === '' || $selection['model'] === '') {
            throw new SpeechToTextException('Save and test your license key in Settings before transcribing audio.');
        }

        $this->limits->assertSingleIsAllowed($options, $file);
        $stream = $this->files->openStream($file);
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

        return $this->payloads->single($payload, $selection);
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

        $files = array_map(fn (array $clip): array => $this->files->resolve($clip['audio']), $clips);
        $this->limits->assertBatchIsAllowed($clips, $files);
        $request = $this->api->request();
        $streams = [];
        $url = $this->api->url('/transcribe');

        try {
            foreach ($files as $file) {
                $stream = $this->files->openStream($file);
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
            $this->files->closeStreams($streams);
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
        return $this->payloads->batch($payload, $clips, $selection);
    }
}
