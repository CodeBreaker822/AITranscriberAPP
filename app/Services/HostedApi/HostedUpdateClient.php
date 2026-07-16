<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Support\ServiceUserMessage;
use App\Services\Http\TrustedHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

class HostedUpdateClient
{
    public function __construct(
        private readonly HostedApiClient $api,
        private readonly TrustedHttpClient $http,
    ) {}

    public function downloadUpdateArchive(?string $downloadUrl = null): Response
    {
        $url = $this->api->updateArchiveUrl($downloadUrl);

        try {
            return $this->api->request()
                ->timeout(600)
                ->withOptions($this->api->trustedOptions(['stream' => true]))
                ->get($url);
        } catch (ConnectionException $exception) {
            $this->http->logConnectionFailure('Hosted update download connection failed.', $url, $exception);
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('update server'), 0, $exception);
        }
    }
}
