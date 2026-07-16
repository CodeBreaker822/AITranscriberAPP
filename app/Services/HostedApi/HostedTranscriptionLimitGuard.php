<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Config\AppSettingsService;

class HostedTranscriptionLimitGuard
{
    public function __construct(private readonly AppSettingsService $settings) {}

    public function assertSingleIsAllowed(array $options, array $file): void
    {
        if ($this->audioExceedsServerBatchLimit($options)) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }

        $this->assertUploadBytesAreAllowed([$file]);
    }

    public function assertBatchIsAllowed(array $clips, array $files): void
    {
        $maxClips = $this->settings->transcribeMaxBatchClips();

        if ($maxClips !== null && count($clips) > $maxClips) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }

        $maxDurationMs = $this->settings->transcribeMaxBatchDurationMs();

        if ($maxDurationMs !== null) {
            $durationMs = 0;

            foreach ($clips as $clip) {
                $clipStartMs = $clip['clip_start_ms'] ?? null;
                $clipEndMs = $clip['clip_end_ms'] ?? null;

                if (! is_numeric($clipStartMs) || ! is_numeric($clipEndMs)) {
                    continue;
                }

                $clipDurationMs = max(0, (int) $clipEndMs - (int) $clipStartMs);

                if ($clipDurationMs > $maxDurationMs) {
                    throw new SpeechToTextException('Audio is too big.', 422);
                }

                $durationMs += $clipDurationMs;
            }

            if ($durationMs > $maxDurationMs) {
                throw new SpeechToTextException('Audio is too big.', 422);
            }
        }

        $this->assertUploadBytesAreAllowed($files);
    }

    private function audioExceedsServerBatchLimit(array $options): bool
    {
        $maxDurationMs = $this->settings->transcribeMaxBatchDurationMs();

        if ($maxDurationMs === null) {
            return false;
        }

        $clipStartMs = $options['clip_start_ms'] ?? null;
        $clipEndMs = $options['clip_end_ms'] ?? null;

        if (! is_numeric($clipStartMs) || ! is_numeric($clipEndMs)) {
            return false;
        }

        return max(0, (int) $clipEndMs - (int) $clipStartMs) > $maxDurationMs;
    }

    /**
     * @param  array<int, array{path: string, name: string, size: int}>  $files
     */
    private function assertUploadBytesAreAllowed(array $files): void
    {
        $maxBytes = $this->settings->transcribeMaxUploadBytes();

        if ($maxBytes === null) {
            return;
        }

        $totalBytes = array_sum(array_map(
            fn (array $file): int => max(0, (int) $file['size']),
            $files,
        ));

        if ($totalBytes > $maxBytes) {
            throw new SpeechToTextException('Audio is too big.', 422);
        }
    }
}
