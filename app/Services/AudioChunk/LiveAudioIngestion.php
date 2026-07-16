<?php

namespace App\Services\AudioChunk;

use App\Enums\AudioChunkStatus;
use App\Enums\TranscriptionEngine;
use App\Exceptions\SpeechToTextException;
use App\Services\Audio\AudioFileChunkerService;
use App\Services\Support\ServiceUserMessage;
use App\Services\Speakers\SpeakerDiarizationService;
use App\Services\Audio\SpeechAudioFilterService;
use App\Services\Speech\SpeechToTextService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LiveAudioIngestion
{
    public function __construct(
        private readonly SpeechToTextService $speechToText,
        private readonly AudioFileChunkerService $chunker,
        private readonly SpeechAudioFilterService $speechFilter,
        private readonly SpeakerDiarizationService $speakerDiarization,
        private readonly AudioChunkPayloadService $payloads,
        private readonly AudioChunkPersistenceService $persistence,
    ) {}

    public function store(mixed $file, array $validated): AudioChunkIngestionResult
    {
        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? ''));
        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $preparedClip = null;

        try {
            $preparedClip = $this->chunker->prepareLiveClip($file, (int) $validated['clip_index']);
            $speechAudio = $this->speechFilter->prepare(
                $preparedClip,
                $this->payloads->vadContext($validated, $userId, $categoryName, 'live'),
            );

            if (! $speechAudio['speech_detected']) {
                if ($finalizeSession) {
                    $this->releaseFinalizedSession($validated, $speakerSessionId);
                }
                $this->cleanupPreparedClip($preparedClip);

                return AudioChunkIngestionResult::skipped(
                    $this->payloads->skippedResponseData($validated, 'live', [
                        'vad' => $speechAudio['vad'],
                    ]),
                );
            }

            $transcriptionAudio = $speechAudio['audio'];
            $transcription = $this->speechToText->transcribe($transcriptionAudio['path'], [
                'language_code' => $validated['language_code'] ?? 'multi',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
                ...(isset($validated['progress_id']) ? ['progress_id' => $validated['progress_id']] : []),
                ...($finalizeSession ? ['release_worker' => true] : []),
            ]);
            $transcription = $this->speakerDiarization->apply($transcriptionAudio['path'], $transcription, [
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'speaker_session_id' => $speakerSessionId,
            ]);
        } catch (SpeechToTextException $exception) {
            $this->cleanupPreparedClip($preparedClip);

            Log::error('Live audio chunk transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return AudioChunkIngestionResult::rejected($exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->cleanupPreparedClip($preparedClip);

            Log::error('Live audio chunk could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return AudioChunkIngestionResult::rejected($exception->getMessage());
        }

        if ($this->payloads->isNoSpeechTranscript($transcription['text'] ?? '')) {
            if ($finalizeSession) {
                $this->speakerDiarization->releaseSession($speakerSessionId);
            }
            $this->cleanupPreparedClip($preparedClip);

            return AudioChunkIngestionResult::skipped($this->payloads->skippedResponseData($validated, 'live'));
        }

        $storedAudio = $transcriptionAudio;
        if (! is_array($storedAudio) || ! is_file($storedAudio['path'])) {
            $this->cleanupPreparedClip($preparedClip);

            return AudioChunkIngestionResult::failed(ServiceUserMessage::audioReadFailed());
        }

        $row = $this->persistence->storeTranscribedAudio(
            $validated,
            $storedAudio,
            $transcription,
            $userId,
            $categoryName,
            'live',
            $speakerSessionId ?: 'live-'.$categoryName,
            AudioChunkStatus::Transcribed->value,
            false,
        );

        $this->cleanupPreparedClip($preparedClip);
        if ($finalizeSession) {
            $this->speakerDiarization->releaseSession($speakerSessionId);
        }

        unset($row['status']);

        return AudioChunkIngestionResult::saved($row);
    }

    private function releaseFinalizedSession(array $validated, string $speakerSessionId): void
    {
        $this->speechToText->releaseOfflineWorker([
            'engine' => $validated['transcription_engine'] ?? TranscriptionEngine::Online->value,
        ]);
        $this->speakerDiarization->releaseSession($speakerSessionId);
    }

    private function cleanupPreparedClip(?array $preparedClip): void
    {
        if (is_array($preparedClip) && isset($preparedClip['directory'])) {
            $this->chunker->cleanup((string) $preparedClip['directory']);
        }
    }
}
