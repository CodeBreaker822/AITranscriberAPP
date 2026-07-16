<?php

namespace App\Services\Speech;

use RuntimeException;

class OfflineWhisperModelCatalog
{
    public const FILE_NAME = 'ggml-large-v3-turbo-q8_0.bin';
    public const DEFAULT_MODEL = 'turbo';

    private const MODELS = [
        'tiny' => [
            'label' => 'Tiny',
            'file' => 'ggml-tiny-q8_0.bin',
            'size' => '42 MiB',
            'min_bytes' => 40000000,
            'sha1' => '19e8118f6652a650569f5a949d962154e01571d9',
            'runtime_memory_mb' => 512,
            'gpu_memory_mb' => 512,
        ],
        'small' => [
            'label' => 'Small',
            'file' => 'ggml-small-q8_0.bin',
            'size' => '252 MiB',
            'min_bytes' => 240000000,
            'sha1' => 'bcad8a2083f4e53d648d586b7dbc0cd673d8afad',
            'runtime_memory_mb' => 1024,
            'gpu_memory_mb' => 1024,
        ],
        'medium' => [
            'label' => 'Medium',
            'file' => 'ggml-medium-q8_0.bin',
            'size' => '785 MiB',
            'min_bytes' => 750000000,
            'sha1' => 'e66645948aff4bebbec71b3485c576f3d63af5d6',
            'runtime_memory_mb' => 2304,
            'gpu_memory_mb' => 2048,
        ],
        'large' => [
            'label' => 'Large v3',
            'file' => 'ggml-large-v3-q5_0.bin',
            'size' => '1.1 GiB',
            'min_bytes' => 1000000000,
            'sha1' => 'e6e2ed78495d403bef4b7cff42ef4aaadcfea8de',
            'runtime_memory_mb' => 3584,
            'gpu_memory_mb' => 3072,
        ],
        'turbo' => [
            'label' => 'Turbo',
            'file' => self::FILE_NAME,
            'size' => '834 MiB',
            'min_bytes' => 800000000,
            'sha1' => '01bf15bedffe9f39d65c1b6ff9b687ea91f59e0e',
            'runtime_memory_mb' => 2560,
            'gpu_memory_mb' => 2560,
        ],
        'cebuano-turbo-ct2' => [
            'label' => 'Cebuano/Bisaya Turbo',
            'file' => 'model.bin',
            'size' => '1.5 GiB',
            'min_bytes' => 1500000000,
            'sha1' => '',
            'runtime_memory_mb' => 2560,
            'gpu_memory_mb' => 2560,
            'runtime' => 'ctranslate2',
            'downloadable' => false,
            'source_url' => 'https://huggingface.co/arrow2026/whisper-turbo-cebuano-epoch1-ct2/tree/main',
            'unsupported_reason' => 'Requires a CTranslate2/faster-whisper runtime. The current offline engine supports whisper.cpp ggml models only.',
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::MODELS;
    }

    /** @return array<int, string> */
    public function modelIds(): array
    {
        return array_keys(self::MODELS);
    }

    /** @return array<string, mixed> */
    public function model(string $model): array
    {
        if (! isset(self::MODELS[$model])) {
            throw new RuntimeException("Unsupported offline Whisper model: {$model}");
        }

        return self::MODELS[$model];
    }

    public function expectedSha1(string $model = self::DEFAULT_MODEL): string
    {
        $override = $model === self::DEFAULT_MODEL
            ? trim((string) config('services.whisper.model_sha1'))
            : '';

        return strtolower($override !== '' ? $override : (string) $this->model($model)['sha1']);
    }

    public function requiredMemoryMb(string $model): int
    {
        return (int) $this->model($model)['runtime_memory_mb'];
    }

    public function requiredGpuMemoryMb(string $model): int
    {
        return (int) $this->model($model)['gpu_memory_mb'];
    }

    public function minimumBytes(string $model): int
    {
        $override = config('services.whisper.model_min_bytes');

        return max(1, (int) ($override !== null && $override !== ''
            ? $override
            : $this->model($model)['min_bytes']));
    }

    public function isWhisperCppModel(string $model): bool
    {
        return ($this->model($model)['runtime'] ?? 'whisper.cpp') === 'whisper.cpp';
    }

    public function downloadable(string $model): bool
    {
        $definition = $this->model($model);

        return ($definition['downloadable'] ?? true) === true && $this->isWhisperCppModel($model);
    }

    public function unsupportedReason(string $model): string
    {
        return (string) ($this->model($model)['unsupported_reason'] ?? 'This offline model is not compatible with the current runtime.');
    }

    /** @return array<int, array{id: string, label: string, size: string}> */
    public function transcriptionCatalog(): array
    {
        $models = array_filter(
            self::MODELS,
            fn (array $definition): bool => ($definition['runtime'] ?? 'whisper.cpp') === 'whisper.cpp',
        );

        return array_map(
            fn (array $definition, string $id): array => [
                'id' => $id,
                'label' => (string) $definition['label'],
                'size' => (string) $definition['size'],
            ],
            $models,
            array_keys($models),
        );
    }
}
