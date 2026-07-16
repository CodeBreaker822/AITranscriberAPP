<?php

namespace App\Jobs;

use App\Exceptions\TranscriptPolisherException;
use App\Services\Audio\UploadedAudioSectionPreparationService;
use App\Services\AudioChunk\AudioChunkIngestionResult;
use App\Services\AudioChunk\AudioChunkIngestionService;
use App\Services\AudioChunk\UploadedAudioBatchPreparationService;
use App\Services\BackgroundJobs\BackgroundJobStore;
use App\Services\Transcripts\TranscriptFurnishService;
use App\Services\Transcripts\TranscriptSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class RunBackgroundJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public string $action,
        public array $payload,
    ) {
        $this->onQueue($this->queueFor($action));
    }

    public function handle(
        BackgroundJobStore $jobs,
        UploadedAudioBatchPreparationService $batchPreparer,
        UploadedAudioSectionPreparationService $sectionPreparer,
        AudioChunkIngestionService $ingestion,
        TranscriptFurnishService $furnisher,
        TranscriptSummaryService $summaries,
    ): void {
        if ($jobs->cancelled($this->jobId)) {
            return;
        }

        $jobs->markRunning($this->jobId);

        try {
            $result = match ($this->action) {
                'prepare_upload_sections_batch' => [
                    'payload' => $batchPreparer->prepare($this->payload),
                    'status' => 200,
                ],
                'prepare_upload_section' => [
                    'payload' => [
                        'message' => 'prepared',
                        'data' => $sectionPreparer->prepare($this->payload),
                    ],
                    'status' => 200,
                ],
                'store_uploaded_batch' => $this->ingestionResult(
                    $ingestion->storeUploadedBatch($this->payload),
                ),
                'store_uploaded_section' => $this->ingestionResult(
                    $ingestion->storeUploadedSection($this->payload),
                ),
                'polish_transcript_chunks' => [
                    'payload' => $furnisher->polishAudioChunks(
                        (int) ($this->payload['user_id'] ?? 1),
                        trim((string) ($this->payload['category_name'] ?? '')),
                        is_array($this->payload['audio_chunk_ids'] ?? null)
                            ? $this->payload['audio_chunk_ids']
                            : [],
                        trim((string) ($this->payload['instructions'] ?? '')),
                    ),
                    'status' => 200,
                ],
                'summarize_transcript' => [
                    'payload' => $summaries->summarizeProject(
                        (int) ($this->payload['user_id'] ?? 1),
                        trim((string) ($this->payload['category_name'] ?? '')),
                        (string) ($this->payload['source_type'] ?? 'raw'),
                    ),
                    'status' => 200,
                ],
                default => throw new \RuntimeException('Unknown background job action.'),
            };

            if ($jobs->cancelled($this->jobId)) {
                return;
            }

            $jobs->markCompleted($this->jobId, $result['payload'], $result['status']);
        } catch (TranscriptPolisherException $exception) {
            $this->failJob($jobs, $exception, $exception->getMessage() ?: 'Transcript processing failed.', 422);
        } catch (Throwable $exception) {
            $this->failJob($jobs, $exception, $exception->getMessage() ?: 'Background processing failed.', 500);
        }
    }

    private function queueFor(string $action): string
    {
        return in_array($action, ['polish_transcript_chunks', 'summarize_transcript'], true)
            ? 'transcripts'
            : 'audio';
    }

    private function ingestionResult(AudioChunkIngestionResult $result): array
    {
        $status = match ($result->type) {
            AudioChunkIngestionResult::SAVED => 201,
            AudioChunkIngestionResult::SKIPPED => 200,
            AudioChunkIngestionResult::REJECTED => 422,
            AudioChunkIngestionResult::FAILED => 500,
            default => 500,
        };

        return [
            'payload' => $result->toResponsePayload(),
            'status' => $status,
        ];
    }

    private function failJob(BackgroundJobStore $jobs, Throwable $exception, string $fallbackMessage, int $defaultStatus): void
    {
        if ($jobs->cancelled($this->jobId)) {
            return;
        }

        $status = (int) $exception->getCode();
        $jobs->markFailed(
            $this->jobId,
            $exception->getMessage() ?: $fallbackMessage,
            $status >= 400 && $status <= 599 ? $status : $defaultStatus,
        );
    }
}
