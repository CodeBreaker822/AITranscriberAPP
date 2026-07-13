<?php

namespace App\Services\AudioChunk;

class AudioChunkIngestionService
{
    public function __construct(
        private readonly LiveAudioIngestion $live,
        private readonly UploadedSectionIngestion $uploadedSection,
        private readonly UploadedBatchIngestion $uploadedBatch,
    ) {}

    public function storeLive(mixed $file, array $validated): AudioChunkIngestionResult
    {
        return $this->live->store($file, $validated);
    }

    public function storeUploadedSection(array $validated): AudioChunkIngestionResult
    {
        return $this->uploadedSection->store($validated);
    }

    public function storeUploadedBatch(array $validated): AudioChunkIngestionResult
    {
        return $this->uploadedBatch->store($validated);
    }
}
