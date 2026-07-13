<?php

namespace Tests\Feature;

use App\Enums\AudioChunkStatus;
use App\Models\AudioChunk;
use App\Services\AudioChunk\AudioChunkPersistenceService;
use App\Services\StoredAudioService;
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

    public function test_audio_chunk_status_endpoint_returns_only_requested_rows(): void
    {
        $firstId = DB::table('audio_chunks')->insertGetId([
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
            'translated_text' => 'Queued transcript.',
            'transcription_timestamps' => json_encode([['text' => 'Queued transcript.', 'start' => 0, 'end' => 1]]),
            'status' => 'diarization_queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondId = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 2,
            'clip_start_ms' => 60000,
            'clip_end_ms' => 120000,
            'range_label' => '01:00-02:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00002-speech.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio-data',
            'translated_text' => 'Complete transcript.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('audio_chunks')->insert([
            'user_id' => 1,
            'category_name' => 'Other',
            'clip_index' => 3,
            'clip_start_ms' => 120000,
            'clip_end_ms' => 180000,
            'range_label' => '02:00-03:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00003-speech.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio-data',
            'translated_text' => 'Unrequested transcript.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(route('audio-chunks.status', [
            'ids' => [$secondId, $firstId, $firstId],
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondId)
            ->assertJsonPath('data.0.status', 'transcribed')
            ->assertJsonPath('data.0.translated_text', 'Complete transcript.')
            ->assertJsonPath('data.0.source_type', 'upload')
            ->assertJsonPath('data.1.id', $firstId)
            ->assertJsonPath('data.1.status', 'diarization_queued')
            ->assertJsonPath('count', 2);

        $this->assertStringNotContainsString('Unrequested transcript.', $response->getContent());
    }

    public function test_transcribed_and_prepared_audio_chunks_share_persistence_metadata(): void
    {
        $transcribedPath = tempnam(sys_get_temp_dir(), 'aitranscriber-transcribed-');
        $preparedPath = tempnam(sys_get_temp_dir(), 'aitranscriber-prepared-');
        file_put_contents($transcribedPath, 'transcribed audio bytes');
        file_put_contents($preparedPath, 'prepared audio bytes');

        $this->mock(StoredAudioService::class, function ($mock): void {
            $mock->shouldReceive('persistWav')->twice()->andReturnUsing(
                fn (string $path, string $sessionId, int $id): array => [
                    'audio_path' => 'audio/'.$sessionId.'/'.$id.'.flac',
                    'audio_size' => filesize($path) ?: 1,
                    'audio_hash' => hash_file('sha256', $path) ?: str_repeat('0', 64),
                    'mime_type' => 'audio/flac',
                ],
            );
        });

        $service = $this->app->make(AudioChunkPersistenceService::class);
        $validated = [
            'clip_index' => 4,
            'clip_start_ms' => 180000,
            'clip_end_ms' => 240000,
            'range_label' => '03:00-04:00',
            'duration_ms' => 60000,
        ];

        try {
            $transcribed = $service->storeTranscribedAudio(
                $validated,
                [
                    'path' => $transcribedPath,
                    'name' => 'chunk_00004-speech.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($transcribedPath),
                    'duration_ms' => 60000,
                ],
                [
                    'text' => 'Shared metadata transcript.',
                    'timestamps' => [['text' => 'Shared metadata transcript.', 'start' => 0.0, 'end' => 1.5]],
                ],
                1,
                'Meeting',
                'upload',
                'shared-session',
            );
            $prepared = $service->storePreparedAudioForDiarization(
                [
                    ...$validated,
                    'clip_index' => 5,
                    'clip_start_ms' => 240000,
                    'clip_end_ms' => 300000,
                    'range_label' => '04:00-05:00',
                ],
                [
                    'path' => $preparedPath,
                    'name' => 'chunk_00005-speech.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($preparedPath),
                    'duration_ms' => 60000,
                ],
                1,
                'Meeting',
                'upload',
                'shared-session',
            );
        } finally {
            @unlink($transcribedPath);
            @unlink($preparedPath);
        }

        $transcribedRow = AudioChunk::query()->findOrFail($transcribed['id']);
        $preparedRow = AudioChunk::query()->findOrFail($prepared['id']);

        $this->assertSame(AudioChunkStatus::Transcribed->value, $transcribed['status']);
        $this->assertSame(AudioChunkStatus::DiarizationReady->value, $prepared['status']);
        $this->assertSame('audio/flac', $transcribedRow->mime_type);
        $this->assertSame('audio/flac', $preparedRow->mime_type);
        $this->assertSame('chunk_00004-speech.wav', $transcribedRow->original_name);
        $this->assertSame('chunk_00005-speech.wav', $preparedRow->original_name);
        $this->assertSame('Shared metadata transcript.', $transcribedRow->translated_text);
        $this->assertNull($preparedRow->translated_text);
        $this->assertSame([['text' => 'Shared metadata transcript.', 'start' => 0, 'end' => 1.5]], $transcribedRow->transcription_timestamps);
        $this->assertSame([], $preparedRow->transcription_timestamps);
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
