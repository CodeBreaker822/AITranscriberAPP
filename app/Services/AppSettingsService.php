<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppSettingsService
{
    public const BASE_URL = 'transcription_api.base_url';

    public const LICENSE_KEY = 'transcription_api.license_key';

    public const LICENSE_STATUS = 'transcription_api.license_status';

    public const SPEECH_TO_TEXT_PROVIDER = 'speech_to_text.provider';

    public const SPEECH_TO_TEXT_MODEL = 'speech_to_text.model';

    public const RESOURCE_MODE = 'resource.mode';

    public const RESOURCE_CPU_THREADS = 'resource.cpu_threads';

    public const RESOURCE_MEMORY_BUDGET_MB = 'resource.memory_budget_mb';

    public const RESOURCE_GPU_VRAM_BUDGET_MB = 'resource.gpu_vram_budget_mb';

    public function licenseKey(): ?string
    {
        return $this->get(self::LICENSE_KEY);
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
        $this->set(self::LICENSE_KEY, trim($licenseKey));
    }

    public function apiBaseUrl(): string
    {
        $baseUrl = $this->get(self::BASE_URL);

        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            return $this->normalizeApiBaseUrl($baseUrl);
        }

        return $this->normalizeApiBaseUrl((string) config('services.transcription_api.base_url', 'https://dilgaims.site/api'));
    }

    public function setApiBaseUrl(string $baseUrl): void
    {
        $this->set(self::BASE_URL, $this->normalizeApiBaseUrl($baseUrl));
    }

    public function storageIsReady(): bool
    {
        return $this->settingsTableExists();
    }

    public function licenseStatus(): array
    {
        $status = $this->get(self::LICENSE_STATUS);

        if (! is_string($status) || trim($status) === '') {
            return [];
        }

        $decoded = json_decode($status, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setLicenseStatus(array $status): void
    {
        $this->set(self::LICENSE_STATUS, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    public function audioChunkSeconds(): int
    {
        return $this->clampInt((int) config('services.audio.chunk_seconds', 60), 1, 20 * 60);
    }

    public function speechToTextProvider(): string
    {
        $provider = $this->get(self::SPEECH_TO_TEXT_PROVIDER);
        $providers = $this->transcriptionProviderOptions();

        if (is_string($provider) && isset($providers[$provider])) {
            return $provider;
        }

        return (string) (array_key_first($providers) ?? '');
    }

    public function setSpeechToTextProvider(string $provider): void
    {
        $this->set(self::SPEECH_TO_TEXT_PROVIDER, trim($provider));
    }

    public function speechToTextModel(?string $provider = null): string
    {
        $provider = $provider ?: $this->speechToTextProvider();
        $model = $this->get(self::SPEECH_TO_TEXT_MODEL);
        $models = $this->transcriptionModelOptions($provider);

        if (is_string($model) && isset($models[$model])) {
            return $model;
        }

        return (string) (array_key_first($models) ?? '');
    }

    public function setSpeechToTextModel(string $model): void
    {
        $this->set(self::SPEECH_TO_TEXT_MODEL, trim($model));
    }

    /**
     * @return array<string, array{provider: string, name: string, models: array<int, array<string, mixed>>}>
     */
    public function transcriptionProviderOptions(): array
    {
        $status = $this->licenseStatus();

        if (($status['apis']['transcribe']['allowed'] ?? true) !== true) {
            return [];
        }

        $providers = $status['providers']['transcription'] ?? [];

        if (! is_array($providers)) {
            return [];
        }

        $options = [];

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            if (! ($provider['configured'] ?? false) || ! ($provider['enabled'] ?? false) || ! ($provider['connected'] ?? false)) {
                continue;
            }

            $key = (string) ($provider['provider'] ?? '');

            if ($key === '') {
                continue;
            }

            $models = is_array($provider['models'] ?? null) ? $provider['models'] : [];

            if ($models === []) {
                continue;
            }

            $options[$key] = [
                'provider' => $key,
                'name' => (string) ($provider['name'] ?? ucfirst($key)),
                'models' => array_values(array_filter($models, 'is_array')),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, array{id: string, label: string, default_language_code: string, languages: array<int, array<string, string>>}>
     */
    public function transcriptionModelOptions(?string $provider = null): array
    {
        $provider = $provider ?: $this->speechToTextProvider();
        $providerConfig = $this->transcriptionProviderOptions()[$provider] ?? null;

        if (! is_array($providerConfig)) {
            return [];
        }

        $models = [];

        foreach ($providerConfig['models'] as $model) {
            $id = (string) ($model['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $models[$id] = [
                'id' => $id,
                'label' => (string) ($model['label'] ?? $id),
                'default_language_code' => (string) ($model['default_language_code'] ?? ''),
                'languages' => $this->normalizeLanguageOptions($model['languages'] ?? []),
            ];
        }

        return $models;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function speechToTextLanguageOptions(?string $provider = null, ?string $model = null): array
    {
        $provider = $provider ?: $this->speechToTextProvider();
        $model = $model ?: $this->speechToTextModel($provider);
        $modelConfig = $this->transcriptionModelOptions($provider)[$model] ?? null;
        $languages = is_array($modelConfig) ? ($modelConfig['languages'] ?? []) : [];

        if ($languages !== []) {
            return $languages;
        }

        return [
            ['value' => 'multi', 'label' => 'Multilingual'],
        ];
    }

    /**
     * @return array{provider: string, model: string, language: string}
     */
    public function transcriptionSelection(?string $languageCode = null): array
    {
        $provider = $this->speechToTextProvider();
        $model = $this->speechToTextModel($provider);
        $languages = $this->speechToTextLanguageOptions($provider, $model);
        $modelConfig = $this->transcriptionModelOptions($provider)[$model] ?? [];
        $allowed = collect($languages)->pluck('value')->all();
        $language = trim((string) $languageCode);

        if ($language === '' || ! in_array($language, $allowed, true)) {
            $defaultLanguage = (string) ($modelConfig['default_language_code'] ?? '');
            $language = in_array($defaultLanguage, $allowed, true)
                ? $defaultLanguage
                : (string) ($languages[0]['value'] ?? 'multi');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'language' => $language,
        ];
    }

    /**
     * @return array{mode: string, cpu_threads: int, memory_budget_mb: int, gpu_available: bool, gpu_name: string, gpu_vram_mb: int, gpu_vram_budget_mb: int, auto_cpu_threads: int, auto_memory_budget_mb: int, auto_gpu_vram_budget_mb: int, max_cpu_threads: int, max_memory_budget_mb: int, max_gpu_vram_budget_mb: int, total_memory_mb: int, available_memory_mb: int}
     */
    public function resourceProfile(): array
    {
        $maxCpuThreads = $this->hardwareCpuThreadLimit();
        $maxMemoryBudgetMb = $this->hardwareMemoryLimitMb();
        $availableMemoryMb = max(0, (int) config('services.resources.available_memory_mb', 0));
        $gpuAvailable = filter_var(config('services.resources.gpu_available', false), FILTER_VALIDATE_BOOL);
        $gpuName = $gpuAvailable ? trim((string) config('services.resources.gpu_name', '')) : '';
        $maxGpuVramBudgetMb = $gpuAvailable ? max(0, (int) config('services.resources.gpu_vram_mb', 0)) : 0;
        $autoCpuThreads = $this->clampInt((int) config('services.whisper.threads', 2), 1, $maxCpuThreads);
        $autoMemoryBudgetMb = $this->clampMemoryBudget((int) config('services.whisper.memory_budget_mb', 0), $maxMemoryBudgetMb);
        $autoGpuVramBudgetMb = $this->clampGpuVramBudget((int) config('services.whisper.gpu_vram_budget_mb', 0), $maxGpuVramBudgetMb);
        $mode = $this->get(self::RESOURCE_MODE, 'auto') === 'manual' ? 'manual' : 'auto';
        $manualCpuThreads = $this->clampInt((int) $this->get(self::RESOURCE_CPU_THREADS, (string) $autoCpuThreads), 1, $maxCpuThreads);
        $manualMemoryBudgetMb = $this->clampMemoryBudget((int) $this->get(self::RESOURCE_MEMORY_BUDGET_MB, (string) $autoMemoryBudgetMb), $maxMemoryBudgetMb);
        $manualGpuVramBudgetMb = $this->clampGpuVramBudget((int) $this->get(self::RESOURCE_GPU_VRAM_BUDGET_MB, (string) $autoGpuVramBudgetMb), $maxGpuVramBudgetMb);

        return [
            'mode' => $mode,
            'cpu_threads' => $mode === 'manual' ? $manualCpuThreads : $autoCpuThreads,
            'memory_budget_mb' => $mode === 'manual' ? $manualMemoryBudgetMb : $autoMemoryBudgetMb,
            'gpu_available' => $gpuAvailable && $maxGpuVramBudgetMb > 0,
            'gpu_name' => $gpuName,
            'gpu_vram_mb' => $maxGpuVramBudgetMb,
            'gpu_vram_budget_mb' => $mode === 'manual' ? $manualGpuVramBudgetMb : $autoGpuVramBudgetMb,
            'auto_cpu_threads' => $autoCpuThreads,
            'auto_memory_budget_mb' => $autoMemoryBudgetMb,
            'auto_gpu_vram_budget_mb' => $autoGpuVramBudgetMb,
            'max_cpu_threads' => $maxCpuThreads,
            'max_memory_budget_mb' => $maxMemoryBudgetMb,
            'max_gpu_vram_budget_mb' => $maxGpuVramBudgetMb,
            'total_memory_mb' => $maxMemoryBudgetMb,
            'available_memory_mb' => $availableMemoryMb,
        ];
    }

    public function setResourceProfile(string $mode, int $cpuThreads, int $memoryBudgetMb, int $gpuVramBudgetMb = 0): void
    {
        $mode = $mode === 'manual' ? 'manual' : 'auto';
        $maxCpuThreads = $this->hardwareCpuThreadLimit();
        $maxMemoryBudgetMb = $this->hardwareMemoryLimitMb();
        $maxGpuVramBudgetMb = $this->hardwareGpuVramLimitMb();

        $this->set(self::RESOURCE_MODE, $mode);
        $this->set(self::RESOURCE_CPU_THREADS, (string) $this->clampInt($cpuThreads, 1, $maxCpuThreads));
        $this->set(self::RESOURCE_MEMORY_BUDGET_MB, (string) $this->clampMemoryBudget($memoryBudgetMb, $maxMemoryBudgetMb));
        $this->set(self::RESOURCE_GPU_VRAM_BUDGET_MB, (string) $this->clampGpuVramBudget($gpuVramBudgetMb, $maxGpuVramBudgetMb));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (! $this->settingsTableExists()) {
            return $default;
        }

        $setting = $this->setting($key);

        if (! $setting) {
            return $default;
        }

        try {
            return $setting->value === null ? $default : (string) $setting->value;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, ?string $value): void
    {
        if (! $this->settingsTableExists()) {
            return;
        }

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'is_encrypted' => true],
        );
    }

    private function normalizeLanguageOptions(mixed $languages): array
    {
        if (! is_array($languages)) {
            return [];
        }

        return array_values(array_filter(array_map(
            function ($language): ?array {
                if (! is_array($language)) {
                    return null;
                }

                $code = (string) ($language['code'] ?? '');

                if ($code === '') {
                    return null;
                }

                return [
                    'value' => $code,
                    'label' => (string) ($language['label'] ?? $code),
                ];
            },
            $languages,
        )));
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

    private function hardwareCpuThreadLimit(): int
    {
        return max(
            1,
            (int) config('services.resources.logical_processors', 0),
            (int) config('services.whisper.threads', 2),
        );
    }

    private function hardwareMemoryLimitMb(): int
    {
        $totalMemoryMb = max(0, (int) config('services.resources.total_memory_mb', 0));

        if ($totalMemoryMb > 0) {
            return $totalMemoryMb;
        }

        return max(0, (int) config('services.whisper.memory_budget_mb', 0));
    }

    private function hardwareGpuVramLimitMb(): int
    {
        if (! filter_var(config('services.resources.gpu_available', false), FILTER_VALIDATE_BOOL)) {
            return 0;
        }

        return max(0, (int) config('services.resources.gpu_vram_mb', 0));
    }

    private function clampMemoryBudget(int $value, int $maxMemoryBudgetMb): int
    {
        if ($maxMemoryBudgetMb <= 0) {
            return 0;
        }

        return $this->clampInt($value, 1, $maxMemoryBudgetMb);
    }

    private function clampGpuVramBudget(int $value, int $maxGpuVramBudgetMb): int
    {
        if ($maxGpuVramBudgetMb <= 0) {
            return 0;
        }

        return $this->clampInt($value, 0, $maxGpuVramBudgetMb);
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($value, max($min, $max)));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('app_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function setting(string $key): ?AppSetting
    {
        try {
            return AppSetting::query()->where('key', $key)->first();
        } catch (Throwable) {
            return null;
        }
    }
}
