<?php

namespace Tests\Unit;

use App\Enums\AudioChunkStatus;
use PHPUnit\Framework\TestCase;

class AudioChunkStatusTest extends TestCase
{
    public function test_pending_diarization_values_match_the_persisted_status_strings(): void
    {
        $this->assertSame([
            'diarization_queued',
            'diarization_processing',
            'diarization_retrying',
            'diarization_waiting_transcript',
        ], AudioChunkStatus::pendingDiarizationValues());
    }
}
