<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UploadSessionStatusTest extends TestCase
{
    public function test_it_rejects_a_stale_restored_upload_session_before_audio_preparation(): void
    {
        $this->getJson(route('audio-uploads.sessions.status', [
            'session_id' => 'missing-upload-session',
        ]))
            ->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_it_recognizes_an_existing_upload_session(): void
    {
        $sessionId = 'available-upload-session';
        $directory = storage_path('app/private/audio-upload-sessions/'.$sessionId);
        $sourcePath = $directory.'/source.wav';
        File::ensureDirectoryExists($directory);
        file_put_contents($sourcePath, 'source');
        file_put_contents($directory.'/session.json', json_encode([
            'source_path' => $sourcePath,
            'duration_ms' => 1000,
        ]));

        try {
            $this->getJson(route('audio-uploads.sessions.status', [
                'session_id' => $sessionId,
            ]))
                ->assertOk()
                ->assertJsonPath('available', true);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
