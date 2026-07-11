<?php

namespace App\Services\AudioChunk;

use App\Exceptions\SpeechToTextException;
use App\Jobs\DiarizeUploadedAudioBatch;
use App\Services\AppSettingsService;
use App\Services\AudioFileChunkerService;
use App\Services\OnlineAudioTransportService;
use App\Services\ServiceUserMessage;
use App\Services\SpeakerDiarizationService;
use App\Services\SpeechAudioFilterService;
use App\Services\SpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AudioChunkIngestionService
{
    public function __construct(
        private readonly SpeechToTextService $speechToText,
        private readonly AudioFileChunkerService $chunker,
        private readonly SpeechAudioFilterService $speechFilter,
        private readonly SpeakerDiarizationService $speakerDiarization,
        private readonly AppSettingsService $settings,
        private readonly OnlineAudioTransportService $onlineTransport,
        private readonly AudioChunkPayloadService $payloads,
        private readonly AudioChunkPersistenceService $persistence,
        private readonly UploadedDiarizationService $uploadedDiarization,
    ) {}

    public function storeLive(Request $request, array $validated): JsonResponse
    {
        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? ''));
        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $file = $request->file('audio');
        $preparedClip = null;

        try {
            $preparedClip = $this->chunker->prepareLiveClip($file, (int) $validated['clip_index']);
            $speechAudio = $this->speechFilter->prepare(
                $preparedClip,
                $this->payloads->vadContext($validated, $userId, $categoryName, 'live'),
            );

            if (! $speechAudio['speech_detected']) {
                if ($finalizeSession) {
                    $this->speechToText->releaseOfflineWorker([
                        'engine' => $validated['transcription_engine'] ?? 'online',
                    ]);
                    $this->speakerDiarization->releaseSession($speakerSessionId);
                }
                if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                    $this->chunker->cleanup((string) $preparedClip['directory']);
                }

                return response()->json([
                    'message' => 'skipped',
                    'data' => $this->payloads->skippedResponseData($validated, 'live', [
                        'vad' => $speechAudio['vad'],
                    ]),
                ]);
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
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $this->chunker->cleanup((string) $preparedClip['directory']);
            }

            Log::error('Live audio chunk transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $this->chunker->cleanup((string) $preparedClip['directory']);
            }

            Log::error('Live audio chunk could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($this->payloads->isNoSpeechTranscript($transcription['text'] ?? '')) {
            if ($finalizeSession) {
                $this->speakerDiarization->releaseSession($speakerSessionId);
            }
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $this->chunker->cleanup((string) $preparedClip['directory']);
            }

            return response()->json([
                'message' => 'skipped',
                'data' => $this->payloads->skippedResponseData($validated, 'live'),
            ]);
        }

        $storedAudio = $transcriptionAudio ?? $preparedClip;
        if (! is_array($storedAudio) || ! is_file($storedAudio['path'])) {
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $this->chunker->cleanup((string) $preparedClip['directory']);
            }

            return response()->json([
                'message' => ServiceUserMessage::audioReadFailed(),
            ], 500);
        }

        $row = $this->persistence->storeTranscribedAudio(
            $validated,
            $storedAudio,
            $transcription,
            $userId,
            $categoryName,
            'live',
            $speakerSessionId ?: 'live-'.$categoryName,
            'transcribed',
            false,
        );

        if (is_array($preparedClip) && isset($preparedClip['directory'])) {
            $this->chunker->cleanup((string) $preparedClip['directory']);
        }
        if ($finalizeSession && $preparedAudioChunkId <= 0) {
            $this->speakerDiarization->releaseSession($speakerSessionId);
        }

        unset($row['status']);

        return response()->json([
            'message' => 'saved',
            'data' => $row,
        ], 201);
    }

    public function storeUploadedSection(array $validated): JsonResponse
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
                    $this->speechToText->releaseOfflineWorker([
                        'engine' => $validated['transcription_engine'] ?? 'online',
                    ]);
                    $this->speakerDiarization->releaseSession($speakerSessionId);
                }
                $this->cleanupUploadedSection($validated['upload_session_id'], $finalizeSession, ...array_filter([$segment]));

                return response()->json([
                    'message' => 'skipped',
                    'data' => $this->payloads->skippedResponseData($validated, 'upload', [
                        'prepared_duration_ms' => (int) ($segment['duration_ms'] ?? $validated['duration_ms']),
                        'prepared_file_size_bytes' => (int) ($segment['size'] ?? 0),
                    ]),
                ]);
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
                        $this->speechToText->releaseOfflineWorker([
                            'engine' => $validated['transcription_engine'] ?? 'online',
                        ]);
                        $this->speakerDiarization->releaseSession($speakerSessionId);
                    }
                    $this->cleanupUploadedSection($validated['upload_session_id'], $finalizeSession, $segment);

                    return response()->json([
                        'message' => 'skipped',
                        'data' => $this->payloads->skippedResponseData($validated, 'upload', [
                            'prepared_duration_ms' => (int) $segment['duration_ms'],
                            'prepared_file_size_bytes' => (int) $segment['size'],
                            'vad' => $speechAudio['vad'],
                        ]),
                    ]);
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

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio section could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Uploaded audio section could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => ServiceUserMessage::audioPrepareFailed(),
            ], 500);
        }

        if ($this->payloads->isNoSpeechTranscript($transcription['text'] ?? '')) {
            if ($finalizeSession) {
                $this->speechToText->releaseOfflineWorker([
                    'engine' => $validated['transcription_engine'] ?? 'online',
                ]);
                $this->speakerDiarization->releaseSession($speakerSessionId);
            }
            $this->cleanupUploadedSection(
                $validated['upload_session_id'],
                $finalizeSession,
                $segment,
                $transcriptionAudio,
            );

            return response()->json([
                'message' => 'skipped',
                'data' => $this->payloads->skippedResponseData($validated, 'upload', [
                    'prepared_duration_ms' => (int) $segment['duration_ms'],
                    'prepared_file_size_bytes' => (int) $segment['size'],
                ]),
            ]);
        }

        $storedAudio = $transcriptionAudio ?? $segment;
        if (! is_file($storedAudio['path'])) {
            return response()->json([
                'message' => ServiceUserMessage::audioReadFailed(),
            ], 500);
        }

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $preparedAudioChunkId = (int) ($validated['audio_chunk_id'] ?? 0);
        $row = $preparedAudioChunkId > 0
            ? $this->persistence->completePreparedAudioTranscription(
                $preparedAudioChunkId,
                $validated,
                $storedAudio,
                $transcription,
                $userId,
                $categoryName,
                'upload',
                $validated['upload_session_id'],
                $speakerSessionId,
                $this->uploadedDiarization,
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

            if ($finalizeSession) {
                DiarizeUploadedAudioBatch::dispatch([], $speakerSessionId, $validated['upload_session_id'], true)
                    ->delay(now()->addSeconds(5));
            }
        } else {
            $this->cleanupUploadedSection(
                $validated['upload_session_id'],
                $finalizeSession,
                $segment,
                $transcriptionAudio,
            );
        }
        if ($finalizeSession) {
            $this->speakerDiarization->releaseSession($speakerSessionId);
        }

        return response()->json([
            'message' => 'saved',
            'data' => $row,
        ], 201);
    }

    public function storeUploadedBatch(array $validated): JsonResponse
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

            return response()->json(['message' => 'Audio is too big.'], 422);
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
                        ? $this->persistence->completePreparedAudioTranscription(
                            $preparedAudioChunkId,
                            $section,
                            $item['audio'],
                            $transcription,
                            $userId,
                            $categoryName,
                            'upload',
                            $validated['upload_session_id'],
                            $speakerSessionId,
                            $this->uploadedDiarization,
                        )
                        : $this->persistence->storeTranscribedAudio(
                            $section,
                            $item['audio'],
                            $transcription,
                            $userId,
                            $categoryName,
                            'upload',
                            $validated['upload_session_id'],
                            $queueOnlineDiarization ? 'diarization_queued' : 'transcribed',
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

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio batch could not be prepared.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Uploaded audio batch could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'section_count' => count($sections),
            ]);

            return response()->json([
                'message' => ServiceUserMessage::audioPrepareFailed(),
            ], 500);
        }

        $allRetainedDiarizationAudio = $queuedDiarizationAudio + $retainedDiarizationAudio;

        if ($allRetainedDiarizationAudio !== []) {
            $retainedPaths = array_flip(array_values($allRetainedDiarizationAudio));
            $this->chunker->cleanupProcessedFiles(...array_values(array_filter(
                $cleanupFiles,
                fn (array $audio): bool => ! isset($retainedPaths[$audio['path'] ?? '']),
            )));
        } else {
            $this->cleanupUploadedSection(
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

        if ($finalizeSession && $retainedDiarizationAudio !== []) {
            DiarizeUploadedAudioBatch::dispatch([], $speakerSessionId, $validated['upload_session_id'], true)
                ->delay(now()->addSeconds(5));
        }

        return response()->json([
            'message' => 'saved',
            'data' => $rows,
        ], 201);
    }

    private function cleanupUploadedSection(
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
