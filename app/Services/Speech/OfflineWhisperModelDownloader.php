<?php

namespace App\Services\Speech;

use App\Services\Http\FileDownloadClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfflineWhisperModelDownloader
{
    private ?string $resolvedModelUrl = null;

    public function __construct(
        private readonly FileDownloadClient $downloads,
        private readonly OfflineWhisperModelCatalog $catalog,
        private readonly OfflineWhisperModelPaths $paths,
    ) {}

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @param  null|callable(): bool  $cancelled
     */
    public function download(string $model, callable $progress, ?callable $cancelled = null): void
    {
        $cancelled ??= static fn (): bool => false;

        if (! $this->catalog->downloadable($model)) {
            throw new RuntimeException($this->catalog->unsupportedReason($model));
        }

        $failures = [];
        $this->paths->prepareDownloadDirectory($model);

        foreach ($this->modelUrls($model) as $url) {
            $this->resolvedModelUrl = $url;
            $progress(['type' => 'source', 'host' => parse_url($url, PHP_URL_HOST) ?: $url]);
            $result = $this->downloadSource($model, $url, $progress, $cancelled);

            if ($cancelled()) {
                @unlink($this->paths->partialDownloadPath($model));

                throw new RuntimeException('The offline model download was canceled.');
            }

            if (! $result['successful']) {
                $failures[] = (parse_url($url, PHP_URL_HOST) ?: $url).': '.$result['error'];
                continue;
            }

            if (! hash_equals($this->catalog->expectedSha1($model), strtolower($result['sha1']))) {
                @unlink($this->paths->partialDownloadPath($model));
                $failures[] = (parse_url($url, PHP_URL_HOST) ?: $url).': checksum verification failed';
                Log::error('Offline Whisper model checksum verification failed.', [
                    'url' => $url,
                    'model' => $model,
                    'expected_sha1' => $this->catalog->expectedSha1($model),
                    'actual_sha1' => $result['sha1'],
                ]);
                continue;
            }

            @unlink($this->paths->downloadPath($model));

            if (! rename($this->paths->partialDownloadPath($model), $this->paths->downloadPath($model))) {
                @unlink($this->paths->partialDownloadPath($model));
                throw new RuntimeException('The verified offline model could not be finalized.');
            }

            $progress(['type' => 'complete', 'model' => $model, 'received_bytes' => $result['received_bytes']]);

            return;
        }

        $detail = $failures !== [] ? implode(' | ', $failures) : 'No download source is configured.';

        throw new RuntimeException('All offline model sources failed. '.$detail);
    }

    public function modelUrl(): string
    {
        return $this->resolvedModelUrl ?? ($this->modelUrls(OfflineWhisperModelCatalog::DEFAULT_MODEL)[0] ?? '');
    }

    /** @return array<int, string> */
    public function modelUrls(string $model = OfflineWhisperModelCatalog::DEFAULT_MODEL): array
    {
        $definition = $this->catalog->model($model);
        $primaryOverride = $model === OfflineWhisperModelCatalog::DEFAULT_MODEL
            ? trim((string) config('services.whisper.model_url'))
            : '';
        $fallbackOverride = $model === OfflineWhisperModelCatalog::DEFAULT_MODEL
            ? trim((string) config('services.whisper.fallback_model_url'))
            : '';
        $primary = $primaryOverride !== ''
            ? $primaryOverride
            : rtrim((string) config('services.whisper.model_base_url'), '/').'/'.$definition['file'].'?download=true';
        $fallback = $model === OfflineWhisperModelCatalog::DEFAULT_MODEL
            ? $fallbackOverride
            : rtrim((string) config('services.whisper.fallback_model_base_url'), '/').'/'.$definition['file'];

        return array_values(array_unique(array_filter(array_map(
            fn ($url): string => trim((string) $url),
            [$primary, $fallback],
        ))));
    }

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @return array{successful: bool, error: string, sha1: string, received_bytes: int}
     */
    private function downloadSource(string $model, string $url, callable $progress, callable $cancelled): array
    {
        $partialPath = $this->paths->partialDownloadPath($model);
        $result = $this->downloads->download($url, $partialPath, [
            'hash_algorithm' => 'sha1',
            'timeout' => (int) config('services.whisper.download_timeout', 3600),
            'user_agent' => 'AITranscriber Offline Model Installer',
            'progress_min_bytes' => 1024 * 1024,
            'progress' => $progress,
            'cancelled' => $cancelled,
        ]);

        if (! $result['successful']) {
            Log::error('Offline Whisper model cURL download failed.', [
                'url' => $url,
                'effective_url' => $result['effective_url'],
                'status' => $result['status'],
                'curl_code' => $result['curl_code'],
                'curl_error' => $result['curl_error'],
                'ca_bundle' => $this->downloads->caBundlePath(),
                'ca_bundle_exists' => is_file($this->downloads->caBundlePath()),
            ]);

            return [
                'successful' => false,
                'error' => $result['error'],
                'sha1' => '',
                'received_bytes' => $result['received_bytes'],
            ];
        }

        return [
            'successful' => true,
            'error' => '',
            'sha1' => $result['hash'],
            'received_bytes' => $result['received_bytes'],
        ];
    }
}
