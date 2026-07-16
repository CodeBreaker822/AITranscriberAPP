<?php

namespace App\Services\Speech;

use App\Exceptions\SpeechToTextException;
use Illuminate\Support\Facades\Log;

class OfflineWhisperWorkerClient
{
    private const MAX_WORKER_RETRIES = 3;

    /** @return array<string, mixed>|null */
    public function request(array $request): ?array
    {
        $endpointPath = storage_path('app/private/offline-whisper-worker.json');

        if (! is_file($endpointPath)) {
            return null;
        }

        $endpoint = json_decode((string) @file_get_contents($endpointPath), true);
        $address = is_array($endpoint) ? trim((string) ($endpoint['address'] ?? '')) : '';
        $token = is_array($endpoint) ? trim((string) ($endpoint['token'] ?? '')) : '';

        if ($address === '' || $token === '') {
            return null;
        }

        $timeout = max(1, (int) config('services.whisper.timeout', 1800));
        $encoded = json_encode(['token' => $token, ...$request], JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new SpeechToTextException('Offline Whisper worker request could not be sent.');
        }

        $attempts = ($request['action'] ?? null) === 'transcribe' ? self::MAX_WORKER_RETRIES + 1 : 1;
        $lastFailure = 'the worker closed its connection without returning JSON';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $socket = @stream_socket_client(
                'tcp://'.$address,
                $errorCode,
                $errorMessage,
                2,
                STREAM_CLIENT_CONNECT,
            );

            if (! is_resource($socket)) {
                return null;
            }

            stream_set_timeout($socket, $timeout);

            if (fwrite($socket, $encoded."\n") === false) {
                fclose($socket);
                $lastFailure = 'the request could not be written to the worker socket';
                $this->logWorkerRetry($attempt, $attempts, $lastFailure, '');
                usleep(100_000);

                continue;
            }

            $response = stream_get_contents($socket);
            $metadata = stream_get_meta_data($socket);
            fclose($socket);

            if (($metadata['timed_out'] ?? false) === true) {
                throw new SpeechToTextException('Offline Whisper transcription timed out.');
            }

            $payload = json_decode((string) $response, true);

            if (is_array($payload)) {
                if (($payload['retryable'] ?? false) === true && $attempt < $attempts) {
                    $lastFailure = trim((string) ($payload['error'] ?? '')) ?: 'the native worker requested a retry';
                    $this->logWorkerRetry($attempt, $attempts, $lastFailure, (string) $response);
                    usleep(150_000);

                    continue;
                }

                return $payload;
            }

            $lastFailure = trim((string) $response) === ''
                ? 'the worker closed its connection without returning JSON'
                : 'the worker returned malformed JSON: '.json_last_error_msg();
            $this->logWorkerRetry($attempt, $attempts, $lastFailure, (string) $response);

            if ($attempt < $attempts) {
                usleep(150_000);
            }
        }

        throw new SpeechToTextException(
            "Offline Whisper failed after {$attempts} attempts because {$lastFailure}."
        );
    }

    private function logWorkerRetry(int $attempt, int $attempts, string $failure, string $response): void
    {
        Log::warning('Offline Whisper worker exchange failed.', [
            'attempt' => $attempt,
            'max_attempts' => $attempts,
            'failure' => $failure,
            'response_bytes' => strlen($response),
            'response_prefix_hex' => bin2hex(substr($response, 0, 96)),
        ]);
    }
}
