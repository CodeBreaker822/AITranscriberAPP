<?php

namespace App\Services\Speech;

use Illuminate\Support\Facades\File;

class OfflineWhisperModelPaths
{
    public function __construct(private readonly OfflineWhisperModelCatalog $catalog) {}

    public function activeModelPath(string $model = OfflineWhisperModelCatalog::DEFAULT_MODEL): ?string
    {
        if (! $this->catalog->isWhisperCppModel($model)) {
            return null;
        }

        $path = $this->downloadPath($model);

        return is_file($path) && filesize($path) >= $this->catalog->minimumBytes($model)
            ? $path
            : null;
    }

    public function downloadPath(string $model = OfflineWhisperModelCatalog::DEFAULT_MODEL): string
    {
        $definition = $this->catalog->model($model);
        $configured = trim((string) config('services.whisper.model', ''));
        $directory = trim((string) config('services.whisper.model_directory', ''));

        if ($directory === '') {
            $directory = $configured !== ''
                ? dirname($configured)
                : storage_path('app/private/whisper/models');
        }

        return $model === OfflineWhisperModelCatalog::DEFAULT_MODEL && $configured !== ''
            ? $configured
            : $directory.'/'.$definition['file'];
    }

    public function partialDownloadPath(string $model = OfflineWhisperModelCatalog::DEFAULT_MODEL): string
    {
        return $this->downloadPath($model).'.download';
    }

    public function prepareDownloadDirectory(string $model = OfflineWhisperModelCatalog::DEFAULT_MODEL): void
    {
        File::ensureDirectoryExists(dirname($this->downloadPath($model)));
        @unlink($this->partialDownloadPath($model));
    }
}
