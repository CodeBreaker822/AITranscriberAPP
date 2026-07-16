<?php

namespace App\Services\Speakers;

use App\Services\Config\AppSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

class SpeakerDiarizationService
{
    public function __construct(
        private readonly SpeakerDiarizationModelService $models,
        private readonly AppSettingsService $settings,
        private readonly SpeakerDiarizationPolicy $policy,
        private readonly SpeakerTranscriptMerger $merger,
    ) {}

    /**
     * Speaker separation is deliberately best-effort. A local model or worker
     * problem must never discard a successful hosted or Whisper transcript.
     */
    public function apply(string $audioPath, array $transcription, array $options = []): array
    {
        if (! $this->policy->enabled($options['diarization_driver'] ?? null)) {
            return $transcription;
        }

        $segments = $this->diarizeSegments($audioPath, $options);

        if ($segments === []) {
            return $transcription;
        }

        return $this->mergeSegments($audioPath, $transcription, $segments, $options);
    }

    public function canDiarize(): bool
    {
        return $this->policy->enabled() && $this->models->activeModelPaths() !== null;
    }

    /**
     * @return array<int, array{start: float|int, end: float|int, speaker_id: string}>
     */
    public function diarizeSegments(string $audioPath, array $options = []): array
    {
        if (! $this->policy->enabled($options['diarization_driver'] ?? null)) {
            return [];
        }

        $paths = $this->models->activeModelPaths();
        $sessionId = trim((string) ($options['speaker_session_id'] ?? ''));
        $releaseSession = (bool) ($options['release_session'] ?? $options['release_worker'] ?? false);

        if ($paths === null || ! is_file($audioPath)) {
            if ($releaseSession && $sessionId !== '') {
                $this->releaseSession($sessionId);
            }

            return [];
        }

        try {
            $payload = $this->workerRequest([
                'action' => 'diarize',
                'segmentation_model_path' => $paths['segmentation'],
                'embedding_model_path' => $paths['embedding'],
                'audio_path' => $audioPath,
                'threads' => max(1, (int) $this->settings->resourceProfile()['cpu_threads']),
                'threshold' => (float) config('services.speaker_diarization.cluster_threshold', 0.9),
                'match_threshold' => (float) config('services.speaker_diarization.match_threshold', 0.6),
                'max_speakers' => max(1, (int) config('services.speaker_diarization.max_speakers', 16)),
                'speaker_session_id' => $sessionId !== '' ? $sessionId : null,
                'release' => $releaseSession,
            ]);

            if ($payload === null) {
                if (($options['throw_on_failure'] ?? false) === true) {
                    throw new \RuntimeException('Speaker diarization worker is unavailable.');
                }

                return [];
            }

            if (is_string($payload['error'] ?? null) && trim($payload['error']) !== '') {
                throw new \RuntimeException(trim($payload['error']));
            }

            $segments = array_values(array_filter(
                is_array($payload['segments'] ?? null) ? $payload['segments'] : [],
                fn ($segment): bool => is_array($segment)
                    && is_numeric($segment['start'] ?? null)
                    && is_numeric($segment['end'] ?? null)
                    && is_string($segment['speaker_id'] ?? null),
            ));

            return $segments;
        } catch (Throwable $exception) {
            Log::warning('Local speaker diarization was skipped.', [
                'error' => $exception->getMessage(),
                'audio' => basename($audioPath),
            ]);

            if (($options['throw_on_failure'] ?? false) === true) {
                throw $exception;
            }

            return [];
        }
    }

    public function mergeSegments(string $audioPath, array $transcription, array $segments, array $options = []): array
    {
        return $this->merger->merge($audioPath, $transcription, $segments, $options);
    }

    public function releaseWorker(): void
    {
        if (! $this->policy->enabled()) {
            return;
        }

        try {
            $this->workerRequest(['action' => 'release']);
        } catch (Throwable) {
            // The process also releases an idle model automatically.
        }
    }

    public function releaseSession(string $sessionId): void
    {
        if (! $this->policy->enabled()) {
            return;
        }

        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            return;
        }

        try {
            $this->workerRequest([
                'action' => 'release_session',
                'speaker_session_id' => $sessionId,
            ]);
        } catch (Throwable) {
            // Session profiles are also removed by the worker idle timeout.
        }
    }

    /** @return array<string, mixed>|null */
    private function workerRequest(array $request): ?array
    {
        $endpointPath = storage_path('app/private/speaker-diarization-worker.json');

        if (! is_file($endpointPath)) {
            return null;
        }

        $endpoint = json_decode((string) @file_get_contents($endpointPath), true);
        $address = is_array($endpoint) ? trim((string) ($endpoint['address'] ?? '')) : '';
        $token = is_array($endpoint) ? trim((string) ($endpoint['token'] ?? '')) : '';

        if ($address === '' || $token === '') {
            return null;
        }

        $socket = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 2, STREAM_CLIENT_CONNECT);

        if (! is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, max(1, (int) config('services.speaker_diarization.timeout', 900)));
        $encoded = json_encode(['token' => $token, ...$request], JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || fwrite($socket, $encoded."\n") === false) {
            fclose($socket);
            throw new \RuntimeException('Speaker diarization worker request could not be sent.');
        }

        $response = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);

        if (($metadata['timed_out'] ?? false) === true) {
            throw new \RuntimeException('Local speaker diarization timed out.');
        }

        $payload = json_decode((string) $response, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Speaker diarization worker returned an invalid response.');
        }

        return $payload;
    }
}
