<?php

namespace App\Services\AudioChunk;

use App\Enums\TranscriptionEngine;
use App\Exceptions\SpeechToTextException;
use App\Jobs\DiarizeUploadedAudioBatch;
use App\Services\AudioFileChunkerService;
use App\Services\ServiceUserMessage;
use App\Services\SpeakerDiarizationService;
use App\Services\SpeechAudioFilterService;
use App\Services\SpeechToTextService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UploadedSectionIngestion
{
    public function __construct(
        private readonly SpeechToTextService $speechToText,
        private readonly AudioFileChunkerService $chunker,
        private readonly SpeechAudioFilterService $speechFilter,
        private readonly SpeakerDiarizationService $speakerDiarization,
        private readonly AudioChunkPayloadService $payloads,
        private readonly AudioChunkPersistenceService $persistence,
        private readonly PreparedAudioCompletionService $preparedCompletion,
    ) {}

    public function store(array $validated): AudioChunkIngestionResult
    {
        @set_time_limit(0);

        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? $validated['upload_session_id']));
        $segment = null;
        $transcriptionAudio = null;

        try {
            $userId = (int) ($validated['user_id'] ?? 1);
            $categoryName = trim((string) $validated['category_name']);
            $preparedName = trim((string) ($validated['prepared_name'] ?? ''));

            if ($preparedName !== '') {
                $transcriptionAudio = $this->chunker->sessionAudioFile($validated['upload_session_id'], $preparedName);
                $sourceName = trim((string) ($validated['source_name'] ?? ''));
                $segment = $sourceName !== ''
                    ? $this->chunker->sessionAudioFile($validated['upload_session_id'], $sourceName)
                    : $transcriptionAudio;
            } elseif ((bool) ($validated['prepared_skipped'] ?? false)) {
                $sourceName = trim((string) ($validated['source_name'] ?? ''));
                $segment = $sourceName !== ''
                    ? $this->chunker->sessionAudioFile($validated['upload_session_id'], $sourceName)
                    : null;

                if ($finalizeSession) {
                    $this->releaseFinalizedSession($validated, $speakerSessionId);
                }
                $this->cleanupUploadedSectionFiles($validated['upload_session_id'], $finalizeSession, $segment);

                return AudioChunkIngestionResult::skipped(
                    $this->payloads->skippedResponseData($validated, 'upload', [
                        'prepared_duration_ms' => (int) ($segment['duration_ms'] ?? $validated['duration_ms']),
                        'prepared_file_size_bytes' => (int) ($segment['size'] ?? 0),
                    ]),
                );
            } else {
                $segment = $this->chunker->extractSegment(
                    $validated['upload_session_id'],
                    (int) $validated['clip_index'],
                    (int) $validated['clip_start_ms'],
                    (int) $validated['duration_ms'],
                );
                $speechAudio = $this->speechFilter->prepare(
                    $segment,
                    $this->payloads->vadContext($validated, $userId, $categoryName, 'upload'),
                );

                if (! $speechAudio['speech_detected']) {
                    if ($finalizeSession) {
                        $this->releaseFinalizedSession($validated, $speakerSessionId);
                    }
                    $this->cleanupUploadedSectionFiles($validated['upload_session_id'], $finalizeSession, $segment);

                    return AudioChunkIngestionResult::skipped(
                        $this->payloads->skippedResponseData($validated, 'upload', [
                            'prepared_duration_ms' => (int) $segment['duration_ms'],
                            'prepared_file_size_bytes' => (int) $segment['size'],
                            'vad' => $speechAudio['vad'],
                        ]),
                    );
                }

                $transcriptionAudio = $speechAudio['audio'];
            }

            $transcription = $this->speechToText->transcribe($transcriptionAudio['path'], [
                'language_code' => $validated['language_code'] ?? 'multi',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
                ...(isset($validated['progress_id']) ? ['progress_id' => $validated['progress_id']] : []),
                'release_worker' => $finalizeSession,
            ]);
            if ((int) ($validated['audio_chunk_id'] ?? 0) <= 0) {
                $transcription = $this->speakerDiarization->apply($transcriptionAudio['path'], $transcription, [
                    'clip_start_ms' => (int) $validated['clip_start_ms'],
                    'speaker_session_id' => $speakerSessionId,
                ]);
            }
        } catch (SpeechToTextException $exception) {
            Log::error('Uploaded audio section transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return AudioChunkIngestionResult::rejected($exception->getMessage());
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio section could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return AudioChunkIngestionResult::rejected($exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Uploaded audio section could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return AudioChunkIngestionResult::failed(ServiceUserMessage::audioPrepareFailed());
        }

        if ($this->payloads->isNoSpeechTranscript($transcription['text'] ?? '')) {
            if ($finalizeSession) {
                $this->releaseFinalizedSession($validated, $speakerSessionId);
            }
            $this->cleanupUploadedSectionFiles(
                $validated['upload_session_id'],
                $finalizeSession,
                $segment,
                $transcriptionAudio,
            );

            return AudioChunkIngestionResult::skipped(
                $this->payloads->skippedResponseData($validated, 'upload', [
                    'prepared_duration_ms' => (int) $segment['duration_ms'],
                    'prepared_file_size_bytes' => (int) $segment['size'],
                ]),
            );
        }

        $storedAudio = $transcriptionAudio;
        if (! is_file($storedAudio['path'])) {
            return AudioChunkIngestionResult::failed(ServiceUserMessage::audioReadFailed());
        }

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $preparedAudioChunkId = (int) ($validated['audio_chunk_id'] ?? 0);
        $row = $preparedAudioChunkId > 0
            ? $this->preparedCompletion->complete(
                $preparedAudioChunkId,
                $validated,
                $storedAudio,
                $transcription,
                $userId,
                $categoryName,
                'upload',
                $validated['upload_session_id'],
                $speakerSessionId,
            )
            : $this->persistence->storeTranscribedAudio(
                $validated,
                $storedAudio,
                $transcription,
                $userId,
                $categoryName,
                'upload',
                $validated['upload_session_id'],
            );
        unset($row['status']);

        if ($preparedAudioChunkId > 0) {
            $this->chunker->cleanupProcessedFiles(...array_values(array_filter([$segment], 'is_array')));
        } else {
            $this->cleanupUploadedSectionFiles(
                $validated['upload_session_id'],
                $finalizeSession,
                $segment,
                $transcriptionAudio,
            );
        }
        if ($finalizeSession) {
            $this->speakerDiarization->releaseSession($speakerSessionId);
        }

        return AudioChunkIngestionResult::saved($row);
    }

    private function releaseFinalizedSession(array $validated, string $speakerSessionId): void
    {
        $this->speechToText->releaseOfflineWorker([
            'engine' => $validated['transcription_engine'] ?? TranscriptionEngine::Online->value,
        ]);
        $this->speakerDiarization->releaseSession($speakerSessionId);
    }

    private function cleanupUploadedSectionFiles(
        string $sessionId,
        bool $finalizeSession,
        ?array ...$audioFiles,
    ): void {
        if ($finalizeSession) {
            $this->chunker->cleanupSession($sessionId);

            return;
        }

        $this->chunker->cleanupProcessedFiles(...array_values(array_filter($audioFiles, 'is_array')));
    }
}
