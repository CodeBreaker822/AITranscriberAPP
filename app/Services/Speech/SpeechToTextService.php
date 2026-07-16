<?php

namespace App\Services\Speech;

use App\Enums\TranscriptionEngine;
use App\Services\HostedApi\HostedTranscriptionApiService;
use Illuminate\Http\UploadedFile;
use SplFileInfo;

class SpeechToTextService
{
    public function __construct(
        private readonly HostedTranscriptionApiService $api,
        private readonly OfflineWhisperService $offlineWhisper,
    ) {}

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        if (TranscriptionEngine::fromOption($options['engine'] ?? null) === TranscriptionEngine::Offline) {
            return $this->offlineWhisper->transcribe($audio, $options);
        }

        return $this->api->transcribe($audio, $options);
    }

    /**
     * @param  array<int, array{audio: UploadedFile|string|SplFileInfo, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int}>  $clips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int, provider?: string|null, model?: string|null}>
     */
    public function transcribeBatch(array $clips, array $options = []): array
    {
        return $this->api->transcribeBatch($clips, $options);
    }

    public function releaseOfflineWorker(array $options = []): void
    {
        if (TranscriptionEngine::fromOption($options['engine'] ?? null) === TranscriptionEngine::Offline) {
            $this->offlineWhisper->releaseWorker();
        }
    }
}
