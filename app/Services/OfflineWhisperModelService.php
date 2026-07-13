<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfflineWhisperModelService
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

    private ?string $resolvedModelUrl = null;

    public function __construct(
        private readonly TrustedHttpClient $http,
        private readonly AppSettingsService $settings,
    ) {
    }

    public function isInstalled(?string $model = null): bool
    {
        if ($model !== null) {
            return $this->activeModelPath($model) !== null;
        }

        foreach (array_keys(self::MODELS) as $key) {
            if ($this->activeModelPath($key) !== null) {
                return true;
            }
        }

        return false;
    }

    public function hasSupportedInstalledModel(): bool
    {
        foreach (array_keys(self::MODELS) as $model) {
            if ($this->supportsAvailableMemory($model) && $this->isInstalled($model)) {
                return true;
            }
        }

        return false;
    }

    public function activeModelPath(string $model = self::DEFAULT_MODEL): ?string
    {
        if (! $this->isWhisperCppModel($model)) {
            return null;
        }

        $path = $this->downloadPath($model);

        return is_file($path) && filesize($path) >= $this->minimumBytes($model)
            ? $path
            : null;
    }

    public function downloadPath(string $model = self::DEFAULT_MODEL): string
    {
        $definition = $this->model($model);
        $configured = trim((string) config('services.whisper.model', ''));
        $directory = trim((string) config('services.whisper.model_directory', ''));

        if ($directory === '') {
            $directory = $configured !== ''
                ? dirname($configured)
                : storage_path('app/private/whisper/models');
        }

        return $model === self::DEFAULT_MODEL && $configured !== ''
            ? $configured
            : $directory.'/'.$definition['file'];
    }

    public function partialDownloadPath(string $model = self::DEFAULT_MODEL): string
    {
        return $this->downloadPath($model).'.download';
    }

    public function prepareDownloadDirectory(string $model = self::DEFAULT_MODEL): void
    {
        File::ensureDirectoryExists(dirname($this->downloadPath($model)));
        @unlink($this->partialDownloadPath($model));
    }

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @param  null|callable(): bool  $cancelled
     */
    public function download(string $model, callable $progress, ?callable $cancelled = null): void
    {
        $cancelled ??= static fn (): bool => false;
        $definition = $this->model($model);

        if (($definition['downloadable'] ?? true) !== true || ! $this->isWhisperCppModel($model)) {
            throw new RuntimeException((string) ($definition['unsupported_reason'] ?? 'This offline model is not compatible with the current runtime.'));
        }

        $failures = [];
        $this->prepareDownloadDirectory($model);

        foreach ($this->modelUrls($model) as $url) {
            $this->resolvedModelUrl = $url;
            $progress(['type' => 'source', 'host' => parse_url($url, PHP_URL_HOST) ?: $url]);
            $result = $this->downloadSource($model, $url, $progress, $cancelled);

            if ($cancelled()) {
                @unlink($this->partialDownloadPath($model));

                throw new RuntimeException('The offline model download was canceled.');
            }

            if (! $result['successful']) {
                $failures[] = (parse_url($url, PHP_URL_HOST) ?: $url).': '.$result['error'];
                continue;
            }

            if (! hash_equals($this->expectedSha1($model), strtolower($result['sha1']))) {
                @unlink($this->partialDownloadPath($model));
                $failures[] = (parse_url($url, PHP_URL_HOST) ?: $url).': checksum verification failed';
                Log::error('Offline Whisper model checksum verification failed.', [
                    'url' => $url,
                    'model' => $model,
                    'expected_sha1' => $this->expectedSha1($model),
                    'actual_sha1' => $result['sha1'],
                ]);
                continue;
            }

            @unlink($this->downloadPath($model));

            if (! rename($this->partialDownloadPath($model), $this->downloadPath($model))) {
                @unlink($this->partialDownloadPath($model));
                throw new RuntimeException('The verified offline model could not be finalized.');
            }

            $progress(['type' => 'complete', 'model' => $model, 'received_bytes' => $result['received_bytes']]);

            return;
        }

        $detail = $failures !== [] ? implode(' | ', $failures) : 'No download source is configured.';

        throw new RuntimeException('All offline model sources failed. '.$detail);
    }

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @return array{successful: bool, error: string, sha1: string, received_bytes: int}
     */
    private function downloadSource(string $model, string $url, callable $progress, callable $cancelled): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('The bundled PHP cURL extension is unavailable.');
        }

        $partialPath = $this->partialDownloadPath($model);
        $destination = fopen($partialPath, 'wb');

        if ($destination === false) {
            throw new RuntimeException('The offline model download file could not be opened.');
        }

        $hash = hash_init('sha1');
        $receivedBytes = 0;
        $lastReportedBytes = 0;

        if (str_starts_with(strtolower($url), 'file://')) {
            fclose($destination);

            return $this->downloadLocalFileSource($model, $url, $progress, $cancelled);
        }

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) config('services.whisper.download_timeout', 3600),
            CURLOPT_CAINFO => $this->http->caBundlePath(),
            CURLOPT_USERAGENT => 'AITranscriber Offline Model Installer',
            CURLOPT_FAILONERROR => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($destination, $hash, &$receivedBytes, &$lastReportedBytes, $progress, $cancelled): int {
                if ($cancelled()) {
                    return 0;
                }

                $length = strlen($chunk);

                if (fwrite($destination, $chunk) === false) {
                    return 0;
                }

                hash_update($hash, $chunk);
                $receivedBytes += $length;
                $totalBytes = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);

                if (($receivedBytes - $lastReportedBytes) >= (1024 * 1024) || ($totalBytes > 0 && $receivedBytes >= $totalBytes)) {
                    $lastReportedBytes = $receivedBytes;
                    $progress([
                        'type' => 'progress',
                        'received_bytes' => $receivedBytes,
                        'total_bytes' => max(0, $totalBytes),
                    ]);
                }

                return $length;
            },
        ]);

        $executed = curl_exec($handle);
        $curlError = trim(curl_error($handle));
        $curlCode = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);
        fclose($destination);

        $successful = $executed === true && ($status === 0 || ($status >= 200 && $status < 300));

        if (! $successful) {
            @unlink($partialPath);
            $error = $curlError !== '' ? $curlError : "HTTP {$status}";
            Log::error('Offline Whisper model cURL download failed.', [
                'url' => $url,
                'effective_url' => $effectiveUrl,
                'status' => $status,
                'curl_code' => $curlCode,
                'curl_error' => $curlError,
                'ca_bundle' => $this->http->caBundlePath(),
                'ca_bundle_exists' => is_file($this->http->caBundlePath()),
            ]);

            return [
                'successful' => false,
                'error' => $error,
                'sha1' => '',
                'received_bytes' => $receivedBytes,
            ];
        }

        return [
            'successful' => true,
            'error' => '',
            'sha1' => hash_final($hash),
            'received_bytes' => $receivedBytes,
        ];
    }

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @return array{successful: bool, error: string, sha1: string, received_bytes: int}
     */
    private function downloadLocalFileSource(string $model, string $url, callable $progress, callable $cancelled): array
    {
        $partialPath = $this->partialDownloadPath($model);
        $sourcePath = $this->localFilePathFromUrl($url);

        if ($sourcePath === null || ! is_file($sourcePath)) {
            @unlink($partialPath);
            $error = 'Local file does not exist.';
            Log::error('Offline Whisper model cURL download failed.', [
                'url' => $url,
                'effective_url' => $url,
                'status' => 0,
                'curl_code' => 0,
                'curl_error' => $error,
                'ca_bundle' => $this->http->caBundlePath(),
                'ca_bundle_exists' => is_file($this->http->caBundlePath()),
            ]);

            return [
                'successful' => false,
                'error' => $error,
                'sha1' => '',
                'received_bytes' => 0,
            ];
        }

        $source = fopen($sourcePath, 'rb');
        $destination = fopen($partialPath, 'wb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new RuntimeException('The offline model download file could not be opened.');
        }

        $hash = hash_init('sha1');
        $receivedBytes = 0;
        $totalBytes = max(0, (int) filesize($sourcePath));

        try {
            while (! feof($source)) {
                if ($cancelled()) {
                    @unlink($partialPath);

                    return [
                        'successful' => false,
                        'error' => 'The offline model download was canceled.',
                        'sha1' => '',
                        'received_bytes' => $receivedBytes,
                    ];
                }

                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    @unlink($partialPath);

                    return [
                        'successful' => false,
                        'error' => 'Local file could not be read.',
                        'sha1' => '',
                        'received_bytes' => $receivedBytes,
                    ];
                }

                if ($chunk === '') {
                    continue;
                }

                if (fwrite($destination, $chunk) === false) {
                    @unlink($partialPath);

                    return [
                        'successful' => false,
                        'error' => 'Local file could not be copied.',
                        'sha1' => '',
                        'received_bytes' => $receivedBytes,
                    ];
                }

                hash_update($hash, $chunk);
                $receivedBytes += strlen($chunk);
                $progress([
                    'type' => 'progress',
                    'received_bytes' => $receivedBytes,
                    'total_bytes' => $totalBytes,
                ]);
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        return [
            'successful' => true,
            'error' => '',
            'sha1' => hash_final($hash),
            'received_bytes' => $receivedBytes,
        ];
    }

    private function localFilePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = rawurldecode($path);

        if (preg_match('/^\/[A-Za-z]:\//', $path) === 1) {
            $path = ltrim($path, '/');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    public function modelUrl(): string
    {
        return $this->resolvedModelUrl ?? ($this->modelUrls(self::DEFAULT_MODEL)[0] ?? '');
    }

    /** @return array<int, string> */
    public function modelUrls(string $model = self::DEFAULT_MODEL): array
    {
        $definition = $this->model($model);
        $primaryOverride = $model === self::DEFAULT_MODEL
            ? trim((string) config('services.whisper.model_url'))
            : '';
        $fallbackOverride = $model === self::DEFAULT_MODEL
            ? trim((string) config('services.whisper.fallback_model_url'))
            : '';
        $primary = $primaryOverride !== ''
            ? $primaryOverride
            : rtrim((string) config('services.whisper.model_base_url'), '/').'/'.$definition['file'].'?download=true';
        $fallback = $model === self::DEFAULT_MODEL
            ? $fallbackOverride
            : rtrim((string) config('services.whisper.fallback_model_base_url'), '/').'/'.$definition['file'];

        return array_values(array_unique(array_filter(array_map(
            fn ($url): string => trim((string) $url),
            [$primary, $fallback],
        ))));
    }

    public function expectedSha1(string $model = self::DEFAULT_MODEL): string
    {
        $override = $model === self::DEFAULT_MODEL
            ? trim((string) config('services.whisper.model_sha1'))
            : '';

        return strtolower($override !== '' ? $override : $this->model($model)['sha1']);
    }

    public function requiredMemoryMb(string $model): int
    {
        return (int) $this->model($model)['runtime_memory_mb'];
    }

    public function requiredGpuMemoryMb(string $model): int
    {
        return (int) $this->model($model)['gpu_memory_mb'];
    }

    public function supportsAvailableMemory(string $model): bool
    {
        if (! $this->isWhisperCppModel($model)) {
            return false;
        }

        $budget = (int) $this->settings->resourceProfile()['memory_budget_mb'];

        return $budget === 0 || $this->requiredMemoryMb($model) <= $budget;
    }

    private function minimumBytes(string $model): int
    {
        $override = config('services.whisper.model_min_bytes');

        return max(1, (int) ($override !== null && $override !== ''
            ? $override
            : $this->model($model)['min_bytes']));
    }

    public function status(): array
    {
        $models = [];

        foreach (self::MODELS as $key => $definition) {
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
        $models = array_filter(
            self::MODELS,
            fn (array $definition): bool => ($definition['runtime'] ?? 'whisper.cpp') === 'whisper.cpp',
        );

        return array_map(
            fn (array $definition, string $id): array => [
                'id' => $id,
                'label' => $definition['label'],
                'size' => $definition['size'],
            ],
            $models,
            array_keys($models),
        );
    }

    /** @return array<int, string> */
    public function modelIds(): array
    {
        return array_keys(self::MODELS);
    }

    private function model(string $model): array
    {
        if (! isset(self::MODELS[$model])) {
            throw new RuntimeException("Unsupported offline Whisper model: {$model}");
        }

        return self::MODELS[$model];
    }

    private function isWhisperCppModel(string $model): bool
    {
        return ($this->model($model)['runtime'] ?? 'whisper.cpp') === 'whisper.cpp';
    }

}
