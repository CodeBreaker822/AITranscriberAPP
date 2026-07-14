<?php

namespace App\Jobs;

use App\Enums\AudioChunkStatus;
use App\Models\AudioChunk;
use App\Services\AudioFileChunkerService;
use App\Services\AudioChunk\UploadedDiarizationService;
use App\Services\SpeakerDiarizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiarizeUploadedAudioBatch implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 0;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    /**
     * @param  array<int, string>  $audioChunkPaths  Audio chunk ID => retained prepared WAV path.
     */
    public function __construct(
        public array $audioChunkPaths,
        public string $speakerSessionId,
        public string $uploadSessionId,
        public bool $finalizeSession = false,
    ) {
        $this->audioChunkPaths = collect($audioChunkPaths)
            ->mapWithKeys(fn (string $path, int|string $id): array => [(int) $id => $path])
            ->filter(fn (string $path, int $id): bool => $id > 0 && trim($path) !== '')
            ->all();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('diarize-upload-'.$this->speakerSessionId))
                ->releaseAfter(5)
                ->expireAfter(1800),
        ];
    }

    public function handle(
        SpeakerDiarizationService $speakerDiarization,
        AudioFileChunkerService $chunker,
        ?UploadedDiarizationService $uploadedDiarization = null,
    ): void {
        $uploadedDiarization ??= app(UploadedDiarizationService::class);

        foreach ($this->audioChunkPaths as $audioChunkId => $audioPath) {
            $row = AudioChunk::query()->find($audioChunkId);

            if (! $row) {
                $this->deleteRetainedAudio($audioPath, 'missing_audio_chunk', [
                    'audio_chunk_id' => $audioChunkId,
                ]);

                continue;
            }

            if ($row->status === AudioChunkStatus::Transcribed->value) {
                $this->deleteRetainedAudio($audioPath, 'already_transcribed', [
                    'audio_chunk_id' => $audioChunkId,
                ]);

                continue;
            }

            $row->forceFill([
                'status' => AudioChunkStatus::DiarizationProcessing->value,
            ])->save();

            try {
                $this->assertPreparedAudioPath($audioPath);
                $transcription = [
                    'text' => (string) ($row->translated_text ?? ''),
                    'timestamps' => is_array($row->transcription_timestamps)
                        ? $row->transcription_timestamps
                        : [],
                ];

                if ($transcription['timestamps'] !== []) {
                    $merged = $speakerDiarization->apply($audioPath, $transcription, [
                        'clip_start_ms' => (int) $row->clip_start_ms,
                        'speaker_session_id' => $this->speakerSessionId,
                        'throw_on_failure' => true,
                    ]);

                    $row->forceFill([
                        'translated_text' => (string) ($merged['text'] ?? $transcription['text']),
                        'transcription_timestamps' => $merged['timestamps'] ?? $transcription['timestamps'],
                        'status' => AudioChunkStatus::Transcribed->value,
                    ])->save();
                    $this->deleteRetainedAudio($audioPath, 'merged_diarization', [
                        'audio_chunk_id' => $audioChunkId,
                    ]);

                    continue;
                }

                $segments = $speakerDiarization->diarizeSegments($audioPath, [
                    'clip_start_ms' => (int) $row->clip_start_ms,
                    'speaker_session_id' => $this->speakerSessionId,
                    'throw_on_failure' => true,
                ]);

                $uploadedDiarization->finishDiarization($row->refresh(), $audioPath, $segments, $this->speakerSessionId);
            } catch (Throwable $exception) {
                $row->forceFill([
                    'status' => AudioChunkStatus::DiarizationRetrying->value,
                ])->save();
                Log::warning('Queued speaker diarization attempt failed.', [
                    'error' => $exception->getMessage(),
                    'audio_chunk_id' => $audioChunkId,
                    'speaker_session_id' => $this->speakerSessionId,
                    'attempt' => $this->attempts(),
                ]);

                throw $exception;
            }
        }

        $this->finalizeUploadSessionIfReady($speakerDiarization, $chunker);
    }

    public function failed(?Throwable $exception): void
    {
        $ids = array_keys($this->audioChunkPaths);

        AudioChunk::query()
            ->whereKey($ids)
            ->whereIn('status', AudioChunkStatus::pendingDiarizationValues())
            ->update([
                'status' => AudioChunkStatus::DiarizationFailed->value,
                'updated_at' => now(),
            ]);

        foreach ($this->audioChunkPaths as $audioPath) {
            $this->deleteRetainedAudio($audioPath, 'diarization_failed');
        }

        Log::error('Queued speaker diarization exhausted all retries.', [
            'error' => $exception?->getMessage(),
            'audio_chunk_ids' => $ids,
            'speaker_session_id' => $this->speakerSessionId,
        ]);
    }

    private function assertPreparedAudioPath(string $audioPath): void
    {
        $root = realpath(storage_path('app/private/audio-upload-sessions'));
        $path = realpath($audioPath);

        if (! is_string($root)
            || ! is_string($path)
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || preg_match('/^chunk_\d+(?:-speech)?\.wav$/i', basename($path)) !== 1) {
            throw new \RuntimeException('Queued speaker diarization audio is missing or invalid.');
        }
    }

    private function deleteRetainedAudio(string $audioPath, string $reason, array $context = []): void
    {
        if (! is_file($audioPath)) {
            return;
        }

        if (unlink($audioPath)) {
            return;
        }

        Log::warning('Queued speaker diarization retained audio could not be deleted.', [
            ...$context,
            'path' => $audioPath,
            'reason' => $reason,
            'speaker_session_id' => $this->speakerSessionId,
            'upload_session_id' => $this->uploadSessionId,
        ]);
    }

    private function uploadSessionDiarizationFinished(): bool
    {
        return ! AudioChunk::query()
            ->where('audio_path', 'like', 'audio/'.$this->safeUploadSessionId().'/%')
            ->whereIn('status', AudioChunkStatus::pendingDiarizationValues())
            ->exists();
    }

    private function safeUploadSessionId(): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($this->uploadSessionId)) ?: 'recordings';
    }

    private function finalizeUploadSessionIfReady(
        SpeakerDiarizationService $speakerDiarization,
        AudioFileChunkerService $chunker,
    ): void {
        if (! $this->finalizeSession) {
            return;
        }

        if ($this->uploadSessionDiarizationFinished()) {
            $speakerDiarization->releaseSession($this->speakerSessionId);
            $chunker->cleanupSession($this->uploadSessionId);
        }
    }
}
