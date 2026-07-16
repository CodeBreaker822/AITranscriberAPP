<?php

namespace App\Services\Config;

class LicenseSettings
{
    public function __construct(private readonly SettingsStore $settings) {}

    public function licenseKey(): ?string
    {
        return $this->settings->get(AppSettingKey::LICENSE_KEY);
    }

    public function hasLicenseKey(): bool
    {
        $licenseKey = $this->licenseKey();

        return is_string($licenseKey) && trim($licenseKey) !== '';
    }

    public function licenseKeySuffix(int $length = 5): ?string
    {
        $licenseKey = trim((string) $this->licenseKey());

        if ($licenseKey === '') {
            return null;
        }

        return substr($licenseKey, -max(1, $length));
    }

    public function setLicenseKey(string $licenseKey): void
    {
        $this->settings->set(AppSettingKey::LICENSE_KEY, trim($licenseKey));
    }

    public function licenseStatus(): array
    {
        $status = $this->settings->get(AppSettingKey::LICENSE_STATUS);

        if (! is_string($status) || trim($status) === '') {
            return [];
        }

        $decoded = json_decode($status, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setLicenseStatus(array $status): void
    {
        $this->settings->set(AppSettingKey::LICENSE_STATUS, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function licenseStatusLabel(): string
    {
        $status = $this->licenseStatus();

        if (($status['valid'] ?? false) && ($status['active'] ?? false) && ! ($status['expired'] ?? false)) {
            return 'Ready';
        }

        return $this->hasLicenseKey() ? 'Needs server check' : 'Needs license';
    }

    public function licenseStatusMessage(): string
    {
        $status = $this->licenseStatus();

        if ($status === []) {
            return 'Save and test your license key to load available transcription providers.';
        }

        if (! ($status['valid'] ?? false)) {
            return 'The saved license key is invalid.';
        }

        if (! ($status['active'] ?? false)) {
            return 'The saved license key is inactive.';
        }

        if ($status['expired'] ?? false) {
            return 'The saved license key has expired.';
        }

        if ($status['rate_limited'] ?? false) {
            $retryAfter = (int) ($status['rate_limit']['retry_after'] ?? 0);

            return $retryAfter > 0
                ? "This license is rate-limited. Try again in {$retryAfter} seconds."
                : 'This license is rate-limited. Please wait and try again.';
        }

        if (! (($status['apis']['transcribe']['allowed'] ?? false) === true)) {
            return 'This license cannot transcribe audio right now.';
        }

        return 'License connected. Provider, model, and language options are loaded from the server.';
    }

    public function transcribeMaxBatchDurationMs(): ?int
    {
        $value = $this->licenseStatus()['apis']['transcribe']['max_batch_duration_ms'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $durationMs = (int) $value;

        return $durationMs > 0 ? $durationMs : null;
    }

    public function transcribeMaxBatchClips(): ?int
    {
        $value = $this->licenseStatus()['apis']['transcribe']['max_batch_clips'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $clips = (int) $value;

        return $clips > 0 ? $clips : null;
    }

    public function transcribeMaxUploadBytes(): ?int
    {
        $transcribe = $this->licenseStatus()['apis']['transcribe'] ?? [];
        $keys = [
            'max_batch_bytes',
            'max_upload_bytes',
            'max_audio_bytes',
            'max_file_bytes',
        ];

        foreach ($keys as $key) {
            $bytes = $this->positiveInt($transcribe[$key] ?? null);

            if ($bytes !== null) {
                return $bytes;
            }
        }

        return $this->positiveInt(config('services.transcription_api.max_upload_bytes'));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
