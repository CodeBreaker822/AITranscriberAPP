<?php

namespace App\Services\HostedApi;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use SplFileInfo;

class HostedTranscriptionApiService
{
    public function __construct(
        private readonly HostedLicenseClient $license,
        private readonly HostedUpdateClient $updates,
        private readonly HostedTranscriptionClient $transcription,
        private readonly HostedPolishingClient $polishing,
    ) {}

    public function licenseStatus(?string $licenseKey = null): array
    {
        return $this->license->licenseStatus($licenseKey);
    }

    public function serverIsReachable(): bool
    {
        return $this->license->serverIsReachable();
    }

    public function downloadUpdateArchive(?string $downloadUrl = null): Response
    {
        return $this->updates->downloadUpdateArchive($downloadUrl);
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider?: string, model?: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        return $this->transcription->transcribe($audio, $options);
    }

    /**
     * @param  array<int, array{audio: UploadedFile|string|SplFileInfo, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int}>  $clips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int, queue_index?: int, provider?: string|null, model?: string|null}>
     */
    public function transcribeBatch(array $clips, array $options = []): array
    {
        return $this->transcription->transcribeBatch($clips, $options);
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string|null, model: string|null}
     */
    public function polish(string $text, array $timestamps = [], array $options = []): array
    {
        return $this->polishing->polish($text, $timestamps, $options);
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, provider: string|null, model: string|null}
     */
    public function polishChunks(array $chunks, array $options = []): array
    {
        return $this->polishing->polishChunks($chunks, $options);
    }
}
