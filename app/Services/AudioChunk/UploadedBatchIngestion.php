<?php

namespace App\Services\AudioChunk;

use App\Enums\AudioChunkStatus;
use App\Exceptions\SpeechToTextException;
use App\Jobs\DiarizeUploadedAudioBatch;
use App\Services\AppSettingsService;
use App\Services\AudioFileChunkerService;
use App\Services\OnlineAudioTransportService;
use App\Services\ServiceUserMessage;
use App\Services\SpeakerDiarizationService;
use App\Services\SpeechToTextService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UploadedBatchIngestion
{
    public function __construct(
        private readonly SpeechToTextService $speechToText,
        private readonly AudioFileChunkerService $chunker,
        private readonly SpeakerDiarizationService $speakerDiarization,
        private readonly AppSettingsService $settings,
        private readonly OnlineAudioTransportService $onlineTransport,
        private readonly AudioChunkPayloadService $payloads,
        private readonly AudioChunkPersistenceService $persistence,
        private readonly PreparedAudioCompletionService $preparedCompletion,
    ) {}

    public function store(array $validated): AudioChunkIngestionResult
    {
        @set_time_limit(0);

        $sections = array_values($validated['sections']);
        $maxDurationMs = $this->settings->transcribeMaxBatchDurationMs() ?? 1_200_000;
        $totalDurationMs = array_sum(array_map(
            fn (array $section): int => max(0, (int) $section['duration_ms']),
            $sections,
        ));

        if ($totalDurationMs > $maxDurationMs) {
            Log::warning('Uploaded audio batch rejected before transcription because it exceeds the configured duration limit.', [
                'section_count' => count($sections),
                'clip_indexes' => array_map(fn (array $section): int => (int) $section['clip_index'], $sections),
                'total_duration_ms' => $totalDurationMs,
                'max_duration_ms' => $maxDurationMs,
            ]);

            return AudioChunkIngestionResult::rejected('Audio is too big.');
        }

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? $validated['upload_session_id']));
        $cleanupFiles = [];
        $batch = [];
        $rows = [];
        $queuedDiarizationAudio = [];
        $retainedDiarizationAudio = [];

        try {
            foreach ($sections as $section) {
                $sourceName = trim((string) ($section['source_name'] ?? ''));
                $sourceAudio = $sourceName !== ''
                    ? $this->chunker->sessionAudioFile($validated['upload_session_id'], $sourceName)
                    : null;

                if ((bool) ($section['prepared_skipped'] ?? false)) {
                    if ($sourceAudio !== null) {
                        $cleanupFiles[] = $sourceAudio;
                    }

                    $rows[] = $this->payloads->skippedResponseData($section, 'upload', [
                        'prepared_duration_ms' => (int) ($sourceAudio['duration_ms'] ?? $section['duration_ms']),
                        'prepared_file_size_bytes' => (int) ($sourceAudio['size'] ?? 0),
                    ]);

                    continue;
                }

                $preparedName = trim((string) ($section['prepared_name'] ?? ''));

                if ($preparedName === '') {
                    throw new RuntimeException('Prepared audio is missing. Prepare the upload again and retry.');
                }

                $transcriptionAudio = $this->chunker->sessionAudioFile($validated['upload_session_id'], $preparedName);
                $cleanupFiles[] = $transcriptionAudio;
                if ((int) ($section['audio_chunk_id'] ?? 0) > 0) {
                    $retainedDiarizationAudio[(int) $section['audio_chunk_id']] = $transcriptionAudio['path'];
                }

                if ($sourceAudio !== null) {
                    $cleanupFiles[] = $sourceAudio;
                }

                $transportAudio = $this->onlineTransport->fromPreparedWav($transcriptionAudio);
                $cleanupFiles[] = $transportAudio;

                $batch[] = [
                    'section' => $section,
                    'audio' => $transcriptionAudio,
                    'transport_audio' => $transportAudio,
                ];
            }

            if ($batch !== []) {
                $queueOnlineDiarization = $this->speakerDiarization->canDiarize();
                $transcriptions = $this->speechToText->transcribeBatch($this->transcriptionBatchClips($batch), [
                    'language_code' => $validated['language_code'] ?? 'multi',
                    ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                    ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
                    ...(isset($validated['progress_id']) ? ['progress_id' => $validated['progress_id']] : []),
                    'release_worker' => $finalizeSession,
                ]);
                foreach ($batch as $batchIndex => $item) {
                    $section = $item['section'];
                    $transcription = $this->payloads->transcriptionForBatchClip($transcriptions, $section, $batchIndex);
                    if ($this->payloads->isNoSpeechTranscript($transcription['text'] ?? '')) {
                        $rows[] = $this->payloads->skippedResponseData($section, 'upload', [
                            'prepared_duration_ms' => (int) $item['audio']['duration_ms'],
                            'prepared_file_size_bytes' => (int) $item['audio']['size'],
                        ]);

                        continue;
                    }

                    $preparedAudioChunkId = (int) ($section['audio_chunk_id'] ?? 0);
                    $row = $preparedAudioChunkId > 0
                        ? $this->preparedCompletion->complete(
                            $preparedAudioChunkId,
                            $section,
                            $item['audio'],
                            $transcription,
                            $userId,
                            $categoryName,
                            'upload',
                            $validated['upload_session_id'],
                            $speakerSessionId,
                        )
                        : $this->persistence->storeTranscribedAudio(
                            $section,
                            $item['audio'],
                            $transcription,
                            $userId,
                            $categoryName,
                            'upload',
                            $validated['upload_session_id'],
                            $queueOnlineDiarization
                                ? AudioChunkStatus::DiarizationQueued->value
                                : AudioChunkStatus::Transcribed->value,
                        );
                    $rows[] = $row;

                    if ($queueOnlineDiarization && $preparedAudioChunkId <= 0) {
                        $queuedDiarizationAudio[(int) $row['id']] = $item['audio']['path'];
                    }
                }
            }
        } catch (SpeechToTextException $exception) {
            Log::error('Uploaded audio batch transcription failed.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return AudioChunkIngestionResult::rejected($exception->getMessage());
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio batch could not be prepared.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
            ]);

            return AudioChunkIngestionResult::rejected($exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Uploaded audio batch could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'section_count' => count($sections),
            ]);

            return AudioChunkIngestionResult::failed(ServiceUserMessage::audioPrepareFailed());
        }

        $allRetainedDiarizationAudio = $queuedDiarizationAudio + $retainedDiarizationAudio;

        if ($allRetainedDiarizationAudio !== []) {
            $retainedPaths = array_flip(array_values($allRetainedDiarizationAudio));
            $this->chunker->cleanupProcessedFiles(...array_values(array_filter(
                $cleanupFiles,
                fn (array $audio): bool => ! isset($retainedPaths[$audio['path'] ?? '']),
            )));
        } else {
            $this->cleanupUploadedSectionFiles(
                $validated['upload_session_id'],
                $finalizeSession,
                ...array_values(array_filter($cleanupFiles, 'is_array')),
            );
        }

        if ($queuedDiarizationAudio !== []) {
            $queuedItems = collect($queuedDiarizationAudio);
            $lastQueuedId = $queuedItems->keys()->last();

            $queuedItems->each(function (string $audioPath, int $audioChunkId) use ($speakerSessionId, $validated, $finalizeSession, $lastQueuedId): void {
                DiarizeUploadedAudioBatch::dispatch(
                    [$audioChunkId => $audioPath],
                    $speakerSessionId,
                    $validated['upload_session_id'],
                    $finalizeSession && $audioChunkId === $lastQueuedId,
                );
            });
        } elseif ($finalizeSession && $retainedDiarizationAudio === []) {
            $this->speakerDiarization->releaseSession($speakerSessionId);
        }

        return AudioChunkIngestionResult::saved($rows);
    }

    private function cleanupUploadedSectionFiles(
        string $sessionId,
        bool $finalizeSession,
        array ...$audioFiles,
    ): void {
        if ($finalizeSession) {
            $this->chunker->cleanupSession($sessionId);

            return;
        }

        $this->chunker->cleanupProcessedFiles(...$audioFiles);
    }

    private function transcriptionBatchClips(array $batch): array
    {
        $batchStartMs = min(array_map(
            fn (array $item): int => (int) $item['section']['clip_start_ms'],
            $batch,
        ));

        return array_map(
            fn (array $item): array => [
                'audio' => $item['transport_audio']['path'],
                'clip_index' => (int) $item['section']['clip_index'],
                'clip_start_ms' => max(0, (int) $item['section']['clip_start_ms'] - $batchStartMs),
                'clip_end_ms' => max(1, (int) $item['section']['clip_end_ms'] - $batchStartMs),
            ],
            $batch,
        );
    }
}
