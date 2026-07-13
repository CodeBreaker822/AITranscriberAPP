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

    public const FINALIZE_RETRY_DELAY_SECONDS = 5;

    public const MAX_FINALIZE_ATTEMPTS = 720;

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
        public int $finalizeAttempts = 0,
    ) {
        $this->audioChunkPaths = collect($audioChunkPaths)
            ->mapWithKeys(fn (string $path, int|string $id): array => [(int) $id => $path])
            ->filter(fn (string $path, int $id): bool => $id > 0 && trim($path) !== '')
            ->all();
        $this->finalizeAttempts = max(0, $this->finalizeAttempts);
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
                @unlink($audioPath);

                continue;
            }

            if ($row->status === AudioChunkStatus::Transcribed->value) {
                @unlink($audioPath);

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
                    @unlink($audioPath);

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
            @unlink($audioPath);
        }

        Log::error('Queued speaker diarization exhausted all retries.', [
            'error' => $exception?->getMessage(),
            'audio_chunk_ids' => $ids,
            'speaker_session_id' => $this->speakerSessionId,
        ]);

        $this->finalizeUploadSessionIfReady(
            app(SpeakerDiarizationService::class),
            app(AudioFileChunkerService::class),
        );
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

    private function uploadSessionDiarizationFinished(): bool
    {
        return ! AudioChunk::query()
            ->where('audio_path', 'like', 'audio/'.$this->safeUploadSessionId().'/%')
            ->whereIn('status', AudioChunkStatus::pendingDiarizationValues())
            ->exists();
    }

    private function pendingUploadSessionDiarizationStatuses(): array
    {
        return AudioChunk::query()
            ->where('audio_path', 'like', 'audio/'.$this->safeUploadSessionId().'/%')
            ->whereIn('status', AudioChunkStatus::pendingDiarizationValues())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
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

            return;
        }

        if ($this->finalizeAttempts >= self::MAX_FINALIZE_ATTEMPTS) {
            Log::error('Queued speaker diarization finalization stopped before cleanup because pending chunks did not reach a terminal status.', [
                'speaker_session_id' => $this->speakerSessionId,
                'upload_session_id' => $this->uploadSessionId,
                'finalize_attempts' => $this->finalizeAttempts,
                'pending_status_counts' => $this->pendingUploadSessionDiarizationStatuses(),
            ]);

            return;
        }

        self::dispatch([], $this->speakerSessionId, $this->uploadSessionId, true, $this->finalizeAttempts + 1)
            ->delay(now()->addSeconds(self::FINALIZE_RETRY_DELAY_SECONDS));
    }
}
