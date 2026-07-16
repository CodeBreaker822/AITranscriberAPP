<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Support\ServiceUserMessage;
use App\Services\Http\TrustedHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HostedTranscriptionJobClient
{
    public function __construct(
        private readonly HostedApiClient $api,
        private readonly HostedApiErrorMapper $errors,
        private readonly TrustedHttpClient $http,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolveAsyncTranscriptionPayload(array $payload): array
    {
        $jobId = trim((string) ($payload['job_id'] ?? ''));

        if ($jobId === '') {
            return $payload;
        }

        $deadline = microtime(true) + (int) config(
            'services.transcription_api.async_timeout',
            (int) config('services.transcription_api.timeout', 1800),
        );
        $statusUrl = $this->api->url('/transcribe/jobs/'.rawurlencode($jobId));

        do {
            try {
                $response = $this->api->request()
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
                    $this->errors->messageForResponse($response, ServiceUserMessage::transcriptionFailed('Transcription server')),
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
                $message = $this->errors->messageFromPayload($jobPayload)
                    ?? ServiceUserMessage::transcriptionFailed('Transcription server');

                throw new SpeechToTextException($message, (int) ($jobPayload['status_code'] ?? 0));
            }

            usleep(2_000_000);
        } while (microtime(true) < $deadline);

        throw new SpeechToTextException('Transcription server timed out while waiting for the async job.');
    }
}
