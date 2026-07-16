<?php

namespace App\Services\Config;

class AppSettingsService
{
    public const BASE_URL = AppSettingKey::BASE_URL;

    public const LICENSE_KEY = AppSettingKey::LICENSE_KEY;

    public const LICENSE_STATUS = AppSettingKey::LICENSE_STATUS;

    public const SPEECH_TO_TEXT_PROVIDER = AppSettingKey::SPEECH_TO_TEXT_PROVIDER;

    public const SPEECH_TO_TEXT_MODEL = AppSettingKey::SPEECH_TO_TEXT_MODEL;

    public const RESOURCE_MODE = AppSettingKey::RESOURCE_MODE;

    public const RESOURCE_CPU_THREADS = AppSettingKey::RESOURCE_CPU_THREADS;

    public const RESOURCE_MEMORY_BUDGET_MB = AppSettingKey::RESOURCE_MEMORY_BUDGET_MB;

    public const RESOURCE_GPU_VRAM_BUDGET_MB = AppSettingKey::RESOURCE_GPU_VRAM_BUDGET_MB;

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly ApiEndpointSettings $apiEndpoint,
        private readonly LicenseSettings $license,
        private readonly TranscriptionProviderCatalog $transcriptionCatalog,
        private readonly ResourceProfileService $resources,
    ) {}

    public function licenseKey(): ?string
    {
        return $this->license->licenseKey();
    }

    public function hasLicenseKey(): bool
    {
        return $this->license->hasLicenseKey();
    }

    public function licenseKeySuffix(int $length = 5): ?string
    {
        return $this->license->licenseKeySuffix($length);
    }

    public function setLicenseKey(string $licenseKey): void
    {
        $this->license->setLicenseKey($licenseKey);
    }

    public function apiBaseUrl(): string
    {
        return $this->apiEndpoint->apiBaseUrl();
    }

    public function setApiBaseUrl(string $baseUrl): void
    {
        $this->apiEndpoint->setApiBaseUrl($baseUrl);
    }

    public function storageIsReady(): bool
    {
        return $this->settings->storageIsReady();
    }

    public function licenseStatus(): array
    {
        return $this->license->licenseStatus();
    }

    public function setLicenseStatus(array $status): void
    {
        $this->license->setLicenseStatus($status);
    }

    public function licenseStatusLabel(): string
    {
        return $this->license->licenseStatusLabel();
    }

    public function licenseStatusMessage(): string
    {
        return $this->license->licenseStatusMessage();
    }

    public function transcribeMaxBatchDurationMs(): ?int
    {
        return $this->license->transcribeMaxBatchDurationMs();
    }

    public function transcribeMaxBatchClips(): ?int
    {
        return $this->license->transcribeMaxBatchClips();
    }

    public function transcribeMaxUploadBytes(): ?int
    {
        return $this->license->transcribeMaxUploadBytes();
    }

    public function audioChunkSeconds(): int
    {
        return $this->clampInt((int) config('services.audio.chunk_seconds', 60), 1, 20 * 60);
    }

    public function speechToTextProvider(): string
    {
        return $this->transcriptionCatalog->speechToTextProvider();
    }

    public function setSpeechToTextProvider(string $provider): void
    {
        $this->transcriptionCatalog->setSpeechToTextProvider($provider);
    }

    public function speechToTextModel(?string $provider = null): string
    {
        return $this->transcriptionCatalog->speechToTextModel($provider);
    }

    public function setSpeechToTextModel(string $model): void
    {
        $this->transcriptionCatalog->setSpeechToTextModel($model);
    }

    /**
     * @return array<string, array{provider: string, name: string, models: array<int, array<string, mixed>>}>
     */
    public function transcriptionProviderOptions(): array
    {
        return $this->transcriptionCatalog->transcriptionProviderOptions();
    }

    /**
     * @return array<string, array{id: string, label: string, default_language_code: string, languages: array<int, array<string, string>>}>
     */
    public function transcriptionModelOptions(?string $provider = null): array
    {
        return $this->transcriptionCatalog->transcriptionModelOptions($provider);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function speechToTextLanguageOptions(?string $provider = null, ?string $model = null): array
    {
        return $this->transcriptionCatalog->speechToTextLanguageOptions($provider, $model);
    }

    /**
     * @return array{provider: string, model: string, language: string}
     */
    public function transcriptionSelection(?string $languageCode = null): array
    {
        return $this->transcriptionCatalog->transcriptionSelection($languageCode);
    }

    /**
     * @return array{mode: string, cpu_threads: int, memory_budget_mb: int, gpu_available: bool, gpu_name: string, gpu_vram_mb: int, gpu_vram_budget_mb: int, auto_cpu_threads: int, auto_memory_budget_mb: int, auto_gpu_vram_budget_mb: int, max_cpu_threads: int, max_memory_budget_mb: int, max_gpu_vram_budget_mb: int, total_memory_mb: int, available_memory_mb: int}
     */
    public function resourceProfile(): array
    {
        return $this->resources->resourceProfile();
    }

    public function setResourceProfile(string $mode, int $cpuThreads, int $memoryBudgetMb, int $gpuVramBudgetMb = 0): void
    {
        $this->resources->setResourceProfile($mode, $cpuThreads, $memoryBudgetMb, $gpuVramBudgetMb);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->settings->get($key, $default);
    }

    public function set(string $key, ?string $value): void
    {
        $this->settings->set($key, $value);
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($value, max($min, $max)));
    }
}
