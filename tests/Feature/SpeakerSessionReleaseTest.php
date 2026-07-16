<?php

namespace Tests\Feature;

use App\Services\Speakers\SpeakerDiarizationService;
use Tests\TestCase;

class SpeakerSessionReleaseTest extends TestCase
{
    public function test_it_releases_temporary_speaker_profiles(): void
    {
        $this->mock(SpeakerDiarizationService::class, function ($mock): void {
            $mock->shouldReceive('releaseSession')->once()->with('meeting-session-1');
        });

        $this->postJson('/speaker-sessions/release', [
            'speaker_session_id' => 'meeting-session-1',
        ])->assertOk()->assertJsonPath('message', 'released');
    }
}
