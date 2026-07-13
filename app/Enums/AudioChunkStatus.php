<?php

namespace App\Enums;

enum AudioChunkStatus: string
{
    case Transcribed = 'transcribed';
    case DiarizationReady = 'diarization_ready';
    case DiarizationQueued = 'diarization_queued';
    case DiarizationProcessing = 'diarization_processing';
    case DiarizationRetrying = 'diarization_retrying';
    case DiarizationWaitingTranscript = 'diarization_waiting_transcript';
    case DiarizationFailed = 'diarization_failed';

    /**
     * @return array<int, string>
     */
    public static function pendingDiarizationValues(): array
    {
        return [
            self::DiarizationQueued->value,
            self::DiarizationProcessing->value,
            self::DiarizationRetrying->value,
            self::DiarizationWaitingTranscript->value,
        ];
    }
}
