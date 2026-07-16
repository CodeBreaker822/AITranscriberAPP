<?php

namespace App\Services\Transcripts;

class GeminiTranscriptCleanerService extends TranscriptPolisherService
{
    public function clean(string $text, array $timestamps = [], array $options = []): array
    {
        return $this->polish($text, $timestamps, $options);
    }

    public function cleanChunks(array $chunks, array $options = []): array
    {
        return $this->polishChunks($chunks, $options);
    }
}
