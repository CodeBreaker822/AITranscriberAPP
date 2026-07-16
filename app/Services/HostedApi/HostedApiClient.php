<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Config\AppSettingsService;
use App\Services\Http\TrustedHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HostedApiClient
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly TrustedHttpClient $http,
    ) {}

    public function request(?string $licenseKey = null): PendingRequest
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

    public function trustedOptions(array $options = []): array
    {
        return $this->http->options($options);
    }

    public function url(string $path): string
    {
        return $this->settings->apiBaseUrl().'/'.ltrim($path, '/');
    }

    public function baseUrl(): string
    {
        return $this->settings->apiBaseUrl();
    }

    public function updateArchiveUrl(?string $downloadUrl): string
    {
        $downloadUrl = trim((string) $downloadUrl);

        if ($downloadUrl === '') {
            return $this->url('/transcribe/update/zipfile');
        }

        if (preg_match('/^https?:\/\//i', $downloadUrl) === 1) {
            $baseParts = parse_url($this->settings->apiBaseUrl());
            $downloadParts = parse_url($downloadUrl);
            $sameScheme = strtolower((string) ($baseParts['scheme'] ?? '')) === strtolower((string) ($downloadParts['scheme'] ?? ''));
            $sameHost = strtolower((string) ($baseParts['host'] ?? '')) === strtolower((string) ($downloadParts['host'] ?? ''));
            $basePort = (string) ($baseParts['port'] ?? '');
            $downloadPort = (string) ($downloadParts['port'] ?? '');

            if ($sameScheme && $sameHost && $basePort === $downloadPort) {
                return $downloadUrl;
            }

            return $this->url('/transcribe');
        }

        return $this->url($downloadUrl);
    }
}
