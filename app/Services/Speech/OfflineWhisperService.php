<?php

namespace App\Services\Speech;

use App\Exceptions\SpeechToTextException;
use App\Services\Config\AppSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class OfflineWhisperService
{
    public function __construct(
        private readonly OfflineWhisperModelService $models,
        private readonly AppSettingsService $settings,
        private readonly OfflineWhisperAudioResolver $audio,
        private readonly OfflineWhisperBinaryLocator $binaries,
        private readonly OfflineWhisperWorkerClient $worker,
        private readonly OfflineWhisperPayloadNormalizer $payloads,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string, model: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $audioPath = $this->audio->path($audio);
        $model = trim((string) ($options['model'] ?? OfflineWhisperModelService::DEFAULT_MODEL));
        $modelPath = $this->modelPath($model);
        $resourceProfile = $this->settings->resourceProfile();
        $memoryBudgetMb = (int) $resourceProfile['memory_budget_mb'];
        $requiredMemoryMb = $this->models->requiredMemoryMb($model);

        if ($memoryBudgetMb > 0 && $requiredMemoryMb > $memoryBudgetMb) {
            throw new SpeechToTextException(
                "The selected Whisper model needs about {$requiredMemoryMb} MB of working memory, "
                ."but AITranscriber reserved only {$memoryBudgetMb} MB to keep this computer responsive. Choose a smaller model."
            );
        }

        $threadBudget = max(1, (int) $resourceProfile['cpu_threads']);
        $gpuVramBudgetMb = max(0, (int) $resourceProfile['gpu_vram_budget_mb']);
        $useGpu = ($resourceProfile['gpu_available'] ?? false) === true
            && $gpuVramBudgetMb >= $this->models->requiredGpuMemoryMb($model);
        $language = trim((string) ($options['language_code'] ?? 'auto'));
        $workerPayload = $this->worker->request([
            'action' => 'transcribe',
            'model_path' => $modelPath,
            'audio_path' => $audioPath,
            'language' => $language !== '' ? $language : 'auto',
            'threads' => $threadBudget,
            'use_gpu' => $useGpu,
            'gpu_vram_budget_mb' => $gpuVramBudgetMb,
            'progress_id' => trim((string) ($options['progress_id'] ?? '')) ?: null,
            'release' => (bool) ($options['release_worker'] ?? false),
        ]);

        if ($workerPayload !== null) {
            return $this->payloads->normalize($workerPayload);
        }

        $binaryPath = $this->binaries->binaryPath();
        $outputDirectory = storage_path('app/private');
        File::ensureDirectoryExists($outputDirectory);
        $outputPath = tempnam($outputDirectory, 'offline-whisper-');

        if ($outputPath === false) {
            throw new SpeechToTextException('Offline transcription output could not be prepared.');
        }

        $process = new Process(array_values(array_filter([
            $binaryPath,
            '--offline-whisper',
            '--model',
            $modelPath,
            '--audio',
            $audioPath,
            '--language',
            $language !== '' ? $language : 'auto',
            '--threads',
            (string) $threadBudget,
            $useGpu ? '--gpu' : '--cpu',
            '--output',
            $outputPath,
        ], fn ($value): bool => $value !== null)));
        $process->setTimeout((int) config('services.whisper.timeout', 1800));

        try {
            $process->run();
            $payload = json_decode((string) @file_get_contents($outputPath), true);
        } catch (Throwable $exception) {
            throw new SpeechToTextException('Offline Whisper transcription did not finish.', 0, $exception);
        } finally {
            @unlink($outputPath);
        }

        if (! $process->isSuccessful() || ! is_array($payload)) {
            $message = is_array($payload) && is_string($payload['error'] ?? null)
                ? trim($payload['error'])
                : trim($process->getErrorOutput());

            throw new SpeechToTextException($message !== '' ? $message : 'Offline Whisper transcription failed.');
        }

        return $this->payloads->normalize($payload);
    }

    public function releaseWorker(): void
    {
        $this->worker->request(['action' => 'release']);
    }

    public function isAvailable(): bool
    {
        return $this->binaries->findBinaryPath() !== null && $this->models->hasSupportedInstalledModel();
    }

    public function modelIsAvailable(): bool
    {
        return $this->models->hasSupportedInstalledModel();
    }

    private function modelPath(string $model): string
    {
        return $this->models->activeModelPath($model)
            ?? throw new SpeechToTextException('The selected offline Whisper model is not installed.');
    }
}
