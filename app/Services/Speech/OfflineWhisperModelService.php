<?php

namespace App\Services\Speech;

use App\Services\Config\AppSettingsService;

class OfflineWhisperModelService
{
    public const FILE_NAME = OfflineWhisperModelCatalog::FILE_NAME;
    public const DEFAULT_MODEL = OfflineWhisperModelCatalog::DEFAULT_MODEL;

    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly OfflineWhisperModelCatalog $catalog,
        private readonly OfflineWhisperModelPaths $paths,
        private readonly OfflineWhisperModelDownloader $downloader,
    ) {
    }

    public function isInstalled(?string $model = null): bool
    {
        if ($model !== null) {
            return $this->activeModelPath($model) !== null;
        }

        foreach ($this->catalog->modelIds() as $key) {
            if ($this->activeModelPath($key) !== null) {
                return true;
            }
        }

        return false;
    }

    public function hasSupportedInstalledModel(): bool
    {
        foreach ($this->catalog->modelIds() as $model) {
            if ($this->supportsAvailableMemory($model) && $this->isInstalled($model)) {
                return true;
            }
        }

        return false;
    }

    public function activeModelPath(string $model = self::DEFAULT_MODEL): ?string
    {
        return $this->paths->activeModelPath($model);
    }

    public function downloadPath(string $model = self::DEFAULT_MODEL): string
    {
        return $this->paths->downloadPath($model);
    }

    public function partialDownloadPath(string $model = self::DEFAULT_MODEL): string
    {
        return $this->paths->partialDownloadPath($model);
    }

    public function prepareDownloadDirectory(string $model = self::DEFAULT_MODEL): void
    {
        $this->paths->prepareDownloadDirectory($model);
    }

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @param  null|callable(): bool  $cancelled
     */
    public function download(string $model, callable $progress, ?callable $cancelled = null): void
    {
        $this->downloader->download($model, $progress, $cancelled);
    }

    public function modelUrl(): string
    {
        return $this->downloader->modelUrl();
    }

    /** @return array<int, string> */
    public function modelUrls(string $model = self::DEFAULT_MODEL): array
    {
        return $this->downloader->modelUrls($model);
    }

    public function expectedSha1(string $model = self::DEFAULT_MODEL): string
    {
        return $this->catalog->expectedSha1($model);
    }

    public function requiredMemoryMb(string $model): int
    {
        return $this->catalog->requiredMemoryMb($model);
    }

    public function requiredGpuMemoryMb(string $model): int
    {
        return $this->catalog->requiredGpuMemoryMb($model);
    }

    public function supportsAvailableMemory(string $model): bool
    {
        if (! $this->catalog->isWhisperCppModel($model)) {
            return false;
        }

        $budget = (int) $this->settings->resourceProfile()['memory_budget_mb'];

        return $budget === 0 || $this->requiredMemoryMb($model) <= $budget;
    }

    public function status(): array
    {
        $models = [];

        foreach ($this->catalog->all() as $key => $definition) {
            $path = $this->activeModelPath($key);
            $models[] = [
                'id' => $key,
                'label' => $definition['label'],
                'size' => $definition['size'],
                'installed' => $path !== null,
                'size_bytes' => $path !== null ? (int) filesize($path) : 0,
                'runtime_memory_mb' => $definition['runtime_memory_mb'],
                'gpu_memory_mb' => $definition['gpu_memory_mb'],
                'runtime' => $definition['runtime'] ?? 'whisper.cpp',
                'downloadable' => ($definition['downloadable'] ?? true) === true,
                'source_url' => $definition['source_url'] ?? null,
                'unsupported_reason' => $definition['unsupported_reason'] ?? null,
                'supported' => $this->supportsAvailableMemory($key),
            ];
        }

        return [
            'installed' => collect($models)->contains('installed', true),
            'model' => 'large-v3-turbo-q8_0',
            'default_model' => self::DEFAULT_MODEL,
            'resource_profile' => $this->settings->resourceProfile(),
            'models' => $models,
        ];
    }

    public function catalog(): array
    {
        return $this->catalog->transcriptionCatalog();
    }

    /** @return array<int, string> */
    public function modelIds(): array
    {
        return $this->catalog->modelIds();
    }
}
