<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AudioPlaybackTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_upload_audio_is_listed_as_upload_with_play_url(): void
    {
        $id = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00001-speech.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio-data',
            'translated_text' => 'Hello from upload.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/audio-chunks');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.source_type', 'upload')
            ->assertJsonPath('data.0.play_url', route('audio-chunks.audio', ['audioChunk' => $id]));
    }

    public function test_audio_playback_endpoint_streams_stored_audio_bytes(): void
    {
        $id = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 2,
            'clip_start_ms' => 60000,
            'clip_end_ms' => 120000,
            'range_label' => '01:00-02:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'live_00002-speech.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio-data',
            'translated_text' => 'Hello from live.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('audio-chunks.audio', ['audioChunk' => $id]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertSee('audio-data', false);
    }

    public function test_audio_playback_streams_filesystem_audio_and_deletion_removes_the_file(): void
    {
        $relativePath = 'audio/playback-test/3.flac';
        $absolutePath = storage_path('app/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        file_put_contents($absolutePath, 'filesystem-audio');

        $id = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 3,
            'clip_start_ms' => 120000,
            'clip_end_ms' => 180000,
            'range_label' => '02:00-03:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/flac',
            'original_name' => 'chunk_00003-speech.wav',
            'file_size_bytes' => 16,
            'audio_blob' => '',
            'audio_path' => $relativePath,
            'audio_size' => 16,
            'audio_hash' => hash('sha256', 'filesystem-audio'),
            'translated_text' => 'Filesystem audio.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('audio-chunks.audio', ['audioChunk' => $id]));
        $response->assertOk()->assertHeader('Content-Type', 'audio/flac');
        $this->assertSame(realpath($absolutePath), $response->baseResponse->getFile()->getRealPath());
        $this->assertSame('filesystem-audio', file_get_contents($response->baseResponse->getFile()->getPathname()));

        $this->deleteJson(route('audio-chunks.destroy', ['audioChunk' => $id]))->assertOk();
        $this->assertFileDoesNotExist($absolutePath);
    }
}
