<?php

namespace App\Services\Config;

class ApiEndpointSettings
{
    public function __construct(private readonly SettingsStore $settings) {}

    public function apiBaseUrl(): string
    {
        $baseUrl = $this->settings->get(AppSettingKey::BASE_URL);

        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            return $this->normalizeApiBaseUrl($baseUrl);
        }

        return $this->normalizeApiBaseUrl((string) config('services.transcription_api.base_url', 'https://dilgaims.site/api'));
    }

    public function setApiBaseUrl(string $baseUrl): void
    {
        $this->settings->set(AppSettingKey::BASE_URL, $this->normalizeApiBaseUrl($baseUrl));
    }

    private function normalizeApiBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);

        if ($baseUrl === '') {
            return 'https://dilgaims.site/api';
        }

        if (! preg_match('/^https?:\/\//i', $baseUrl)) {
            $baseUrl = 'https://'.$baseUrl;
        }

        return rtrim($baseUrl, '/');
    }
}
