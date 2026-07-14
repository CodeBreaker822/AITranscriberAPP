<?php

namespace App\Services\AudioChunk;

use App\Enums\AudioChunkStatus;
use App\Jobs\DiarizeUploadedAudioBatch;
use App\Models\AudioChunk;
use App\Services\AudioFileChunkerService;
use App\Services\SpeakerDiarizationService;
use Illuminate\Support\Facades\Log;

class UploadedDiarizationService
{
    public function __construct(
        private readonly SpeakerDiarizationService $speakerDiarization,
        private readonly AudioFileChunkerService $chunker,
        private readonly AudioChunkPersistenceService $persistence,
    ) {}

    public function queuePreparedAudio(
        int $audioChunkId,
        string $audioPath,
        string $speakerSessionId,
        string $uploadSessionId,
        bool $finalizeSession = false,
    ): void {
        if (! $this->speakerDiarization->canDiarize()) {
            return;
        }

        DiarizeUploadedAudioBatch::dispatch(
            [$audioChunkId => $audioPath],
            $speakerSessionId,
            $uploadSessionId,
            $finalizeSession,
        );
    }

    /**
     * Queue prepared upload clips after the full VAD preparation phase has ended.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array{queued: array<int, int>, failed: array<int, int>, sections: array<int, array{clip_index: int, audio_chunk_id: int}>}
     */
    public function queuePreparedSections(
        string $uploadSessionId,
        string $speakerSessionId,
        int $userId,
        string $categoryName,
        array $sections,
    ): array {
        if (! $this->speakerDiarization->canDiarize()) {
            return [
                'queued' => [],
                'failed' => [],
                'sections' => [],
            ];
        }

        $queued = [];
        $failed = [];
        $queuedSections = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $audioChunkId = (int) ($section['audio_chunk_id'] ?? 0);
            $preparedName = trim((string) ($section['prepared_name'] ?? ''));

            if ($audioChunkId <= 0 || $preparedName === '') {
                if ($preparedName === '') {
                    continue;
                }
            }

            try {
                $audio = $this->chunker->sessionAudioFile($uploadSessionId, $preparedName);

                if ($audioChunkId <= 0) {
                    $row = $this->persistence->storePreparedAudioForDiarization(
                        $section,
                        $audio,
                        $userId,
                        $categoryName,
                        'upload',
                        $uploadSessionId,
                    );
                    $audioChunkId = (int) $row['id'];
                }

                AudioChunk::query()
                    ->whereKey($audioChunkId)
                    ->where('status', AudioChunkStatus::DiarizationReady->value)
                    ->update([
                        'status' => AudioChunkStatus::DiarizationQueued->value,
                        'updated_at' => now(),
                    ]);
                $this->queuePreparedAudio($audioChunkId, (string) $audio['path'], $speakerSessionId, $uploadSessionId);
                $queued[] = $audioChunkId;
                $queuedSections[] = [
                    'clip_index' => (int) ($section['clip_index'] ?? 0),
                    'audio_chunk_id' => $audioChunkId,
                ];
            } catch (\Throwable $exception) {
                if ($audioChunkId > 0) {
                    $failed[] = $audioChunkId;
                    try {
                        AudioChunk::query()
                            ->whereKey($audioChunkId)
                            ->update([
                                'status' => AudioChunkStatus::DiarizationFailed->value,
                                'updated_at' => now(),
                            ]);
                    } catch (\Throwable $statusException) {
                        Log::warning('Prepared upload diarization failure status could not be persisted.', [
                            'message' => $statusException->getMessage(),
                            'upload_session_id' => $uploadSessionId,
                            'audio_chunk_id' => $audioChunkId,
                        ]);
                    }
                }

                Log::warning('Prepared upload diarization could not be queued.', [
                    'message' => $exception->getMessage(),
                    'upload_session_id' => $uploadSessionId,
                    'audio_chunk_id' => $audioChunkId > 0 ? $audioChunkId : null,
                    'prepared_name' => $preparedName,
                ]);
            }
        }

        return [
            'queued' => array_values(array_unique($queued)),
            'failed' => array_values(array_unique($failed)),
            'sections' => $queuedSections,
        ];
    }

    public function mergePreparedResultIfAvailable(
        AudioChunk $audioChunk,
        string $audioPath,
        array $transcription,
        string $speakerSessionId,
    ): array {
        $segments = $this->readSegments($audioPath);

        if ($segments === []) {
            return $transcription;
        }

        $merged = $this->speakerDiarization->mergeSegments($audioPath, $transcription, $segments, [
            'clip_start_ms' => (int) $audioChunk->clip_start_ms,
            'speaker_session_id' => $speakerSessionId,
        ]);

        $this->deleteSidecar($audioPath);
        @unlink($audioPath);

        return $merged;
    }

    public function hasPreparedResult(string $audioPath): bool
    {
        return is_file($this->sidecarPath($audioPath));
    }

    public function finishDiarization(
        AudioChunk $audioChunk,
        string $audioPath,
        array $segments,
        string $speakerSessionId,
    ): void {
        $transcription = [
            'text' => (string) ($audioChunk->translated_text ?? ''),
            'timestamps' => is_array($audioChunk->transcription_timestamps)
                ? $audioChunk->transcription_timestamps
                : [],
        ];

        if ($transcription['timestamps'] === []) {
            $this->writeSegments($audioPath, $segments);
            $audioChunk->forceFill([
                'status' => AudioChunkStatus::DiarizationWaitingTranscript->value,
            ])->save();

            return;
        }

        $merged = $this->speakerDiarization->mergeSegments($audioPath, $transcription, $segments, [
            'clip_start_ms' => (int) $audioChunk->clip_start_ms,
            'speaker_session_id' => $speakerSessionId,
        ]);

        $audioChunk->forceFill([
            'translated_text' => (string) ($merged['text'] ?? $transcription['text']),
            'transcription_timestamps' => $merged['timestamps'] ?? $transcription['timestamps'],
            'status' => AudioChunkStatus::Transcribed->value,
        ])->save();

        $this->deleteSidecar($audioPath);
        @unlink($audioPath);
    }

    /** @return array<int, array<string, mixed>> */
    private function readSegments(string $audioPath): array
    {
        $path = $this->sidecarPath($audioPath);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<int, array<string, mixed>> $segments */
    private function writeSegments(string $audioPath, array $segments): void
    {
        $encoded = json_encode(array_values($segments), JSON_UNESCAPED_SLASHES);

        if (is_string($encoded)) {
            @file_put_contents($this->sidecarPath($audioPath), $encoded);
        }
    }

    private function deleteSidecar(string $audioPath): void
    {
        @unlink($this->sidecarPath($audioPath));
    }

    private function sidecarPath(string $audioPath): string
    {
        return $audioPath.'.diarization.json';
    }
}
