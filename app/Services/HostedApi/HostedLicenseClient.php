<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Support\ServiceUserMessage;
use App\Services\Http\TrustedHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HostedLicenseClient
{
    public function __construct(
        private readonly HostedApiClient $api,
        private readonly HostedApiErrorMapper $errors,
        private readonly TrustedHttpClient $http,
    ) {}

    public function licenseStatus(?string $licenseKey = null): array
    {
        $url = $this->api->url('/license/status');

        try {
            $response = $this->api->request($licenseKey)->get($url);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure('Hosted license status connection failed.', $url, $exception);
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            $this->http->logHttpFailure('Hosted license status request failed.', $url, $response);
            throw new SpeechToTextException($this->errors->messageForResponse($response, 'License status could not be checked.'));
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
                ->withOptions($this->api->trustedOptions())
                ->get($this->api->baseUrl());

            return true;
        } catch (ConnectionException) {
            return false;
        }
    }
}
