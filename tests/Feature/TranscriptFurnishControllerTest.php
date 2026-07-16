<?php

namespace Tests\Feature;

use App\Exceptions\TranscriptPolisherException;
use App\Services\Transcripts\TranscriptPolisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TranscriptFurnishControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_cleaner_window_is_skipped_without_failing_polish(): void
    {
        DB::table('audio_chunks')->insert([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 6,
            'clip_start_ms' => 300000,
            'clip_end_ms' => 360000,
            'range_label' => '05:00-06:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00002.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => 'Proceed to the next agenda.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(TranscriptPolisherService::class, function ($mock): void {
            $mock->shouldReceive('polishChunks')->never();
        });

        $response = $this->postJson('/transcripts/furnish', [
            'user_id' => 1,
            'category_name' => 'Meeting',
            'window_index' => 0,
            'instructions' => 'Correct punctuation.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'polished')
            ->assertJsonPath('count', 0)
            ->assertJsonPath('data', []);
    }

    public function test_polishing_again_replaces_existing_cleaned_result(): void
    {
        $audioChunkId = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00001.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => 'um hello world',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run = 0;

        $this->mock(TranscriptPolisherService::class, function ($mock) use ($audioChunkId, &$run): void {
            $mock->shouldReceive('polishChunks')
                ->twice()
                ->andReturnUsing(function (array $chunks, array $options) use ($audioChunkId, &$run): array {
                    $run++;

                    return [
                        'chunks' => [[
                            'audio_chunk_id' => $audioChunkId,
                            'text' => "Polished run {$run}",
                            'timestamps' => [],
                        ]],
                        'provider' => 'openai',
                        'model' => 'test-model',
                    ];
                });
        });

        foreach ([1, 2] as $attempt) {
            $this->postJson('/transcripts/furnish', [
                'user_id' => 1,
                'category_name' => 'Meeting',
                'window_index' => 0,
                'instructions' => 'Correct punctuation.',
            ])->assertOk()->assertJsonPath('data.0.clean_text', "Polished run {$attempt}");
        }

        $this->assertDatabaseHas('clean_transcript_chunks', [
            'audio_chunk_id' => $audioChunkId,
            'clean_text' => 'Polished run 2',
            'provider' => 'openai',
            'model' => 'test-model',
            'instruction_hash' => hash('sha256', 'Correct punctuation.'),
        ]);

        $this->assertDatabaseMissing('clean_transcript_chunks', [
            'audio_chunk_id' => $audioChunkId,
            'clean_text' => 'Polished run 1',
        ]);
    }

    public function test_polishing_can_target_exact_audio_chunk_ids_when_clip_times_overlap(): void
    {
        $firstId = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Upload',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00001.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => 'First transcript.',
            'transcription_timestamps' => json_encode([['start' => 0, 'end' => 1, 'text' => 'First transcript.']]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondId = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Upload',
            'clip_index' => 2,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00002.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => 'Second transcript.',
            'transcription_timestamps' => json_encode([['start' => 0, 'end' => 1, 'text' => 'Second transcript.']]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(TranscriptPolisherService::class, function ($mock) use ($secondId): void {
            $mock->shouldReceive('polishChunks')
                ->once()
                ->withArgs(function (array $chunks, array $options = []): bool {
                    return count($chunks) === 1
                        && $chunks[0]['text'] === 'Second transcript.'
                        && $chunks[0]['clip_index'] === 2;
                })
                ->andReturn([
                    'chunks' => [[
                        'audio_chunk_id' => $secondId,
                        'text' => 'Second transcript polished.',
                        'timestamps' => [['start' => 0, 'end' => 1, 'text' => 'Second transcript polished.']],
                    ]],
                    'provider' => 'openai',
                    'model' => 'test-model',
                ]);
        });

        $this->postJson('/transcripts/furnish', [
            'user_id' => 1,
            'category_name' => 'Upload',
            'audio_chunk_ids' => [$secondId],
            'instructions' => 'Correct punctuation.',
        ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.audio_chunk_id', $secondId)
            ->assertJsonPath('data.0.clean_text', 'Second transcript polished.');

        $this->assertDatabaseMissing('clean_transcript_chunks', [
            'audio_chunk_id' => $firstId,
        ]);
    }

    public function test_failed_repolish_preserves_the_last_good_result_and_server_error(): void
    {
        $audioChunkId = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => 'Raw transcript.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clean_transcript_chunks')->insert([
            'audio_chunk_id' => $audioChunkId,
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'raw_text' => 'Raw transcript.',
            'clean_text' => 'Last good result.',
            'clean_timestamps' => json_encode([]),
            'status' => 'cleaned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mock(TranscriptPolisherService::class, function ($mock): void {
            $mock->shouldReceive('polishChunks')->once()->andThrow(
                new TranscriptPolisherException('Provider temporarily unavailable.', 503)
            );
        });

        $this->postJson('/transcripts/furnish', [
            'category_name' => 'Meeting',
            'window_index' => 0,
            'instructions' => 'Correct grammar.',
        ])->assertStatus(503)->assertJsonPath('message', 'Provider temporarily unavailable.');

        $this->assertDatabaseHas('clean_transcript_chunks', [
            'audio_chunk_id' => $audioChunkId,
            'clean_text' => 'Last good result.',
        ]);
    }
}
