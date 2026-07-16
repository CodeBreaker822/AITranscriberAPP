<?php

namespace Tests\Feature;

use App\Exceptions\TranscriptPolisherException;
use App\Services\Transcripts\TranscriptSummarizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TranscriptSummaryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_is_project_scoped_preserves_format_and_is_replaced(): void
    {
        $this->insertChunk('Meeting', 1, 'First agenda item.');
        $this->insertChunk('Meeting', 2, 'Second agenda item.');
        $this->insertChunk('Other project', 1, 'This must not be summarized.');
        $run = 0;

        $this->mock(TranscriptSummarizerService::class, function ($mock) use (&$run): void {
            $mock->shouldReceive('summarize')
                ->twice()
                ->withArgs(function (string $transcript): bool {
                    return str_contains($transcript, 'First agenda item.')
                        && str_contains($transcript, 'Second agenda item.')
                        && ! str_contains($transcript, 'This must not be summarized.');
                })
                ->andReturnUsing(function () use (&$run): array {
                    $run++;

                    return [
                        'text' => $run === 1
                            ? "## Summary\n- First result\n- Keep formatting"
                            : "• Replacement result\n  1. Nested item",
                        'provider' => 'gemini',
                        'model' => 'gemini-summary-model',
                    ];
                });
        });

        $this->postJson('/transcripts/summary', ['category_name' => 'Meeting'])
            ->assertOk()
            ->assertJsonPath('data.summary_text', "## Summary\n- First result\n- Keep formatting");

        $this->postJson('/transcripts/summary', ['category_name' => 'Meeting'])
            ->assertOk()
            ->assertJsonPath('data.summary_text', "• Replacement result\n  1. Nested item");

        $this->assertDatabaseCount('transcript_summaries', 1);
        $this->assertDatabaseHas('transcript_summaries', [
            'category_name' => 'Meeting',
            'summary_text' => "• Replacement result\n  1. Nested item",
            'status' => 'complete',
            'provider' => 'gemini',
            'model' => 'gemini-summary-model',
        ]);

        $this->getJson('/transcripts/summary?category_name=Meeting')
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.summary_text', "• Replacement result\n  1. Nested item");
        $this->getJson('/transcripts/summary?category_name=Other%20project')
            ->assertOk()
            ->assertJsonPath('data.status', 'idle');
    }

    public function test_summary_without_raw_transcript_persists_failed_status(): void
    {
        $this->mock(TranscriptSummarizerService::class, function ($mock): void {
            $mock->shouldReceive('summarize')->never();
        });

        $this->postJson('/transcripts/summary', ['category_name' => 'Empty project'])
            ->assertNotFound();

        $this->getJson('/transcripts/summary?category_name=Empty%20project')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.summary_text', '');
    }

    public function test_user_can_summarize_the_whole_cleaned_transcript(): void
    {
        $audioChunkId = $this->insertChunk('Meeting', 1, 'Raw text must not be selected.');
        DB::table('clean_transcript_chunks')->insert([
            'audio_chunk_id' => $audioChunkId,
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'raw_text' => 'Raw text must not be selected.',
            'clean_text' => 'Cleaned text selected for summary.',
            'clean_timestamps' => json_encode([]),
            'status' => 'cleaned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(TranscriptSummarizerService::class, function ($mock): void {
            $mock->shouldReceive('summarize')
                ->once()
                ->withArgs(fn (string $text): bool => str_contains($text, 'Cleaned text selected for summary.')
                    && ! str_contains($text, 'Raw text must not be selected.'))
                ->andReturn(['text' => '- Clean summary', 'provider' => 'gemini', 'model' => 'summary-model']);
        });

        $this->postJson('/transcripts/summary', [
            'category_name' => 'Meeting',
            'source_type' => 'cleaned',
        ])
            ->assertOk()
            ->assertJsonPath('data.source_type', 'cleaned')
            ->assertJsonPath('data.summary_text', '- Clean summary');
    }

    public function test_summary_can_run_as_a_background_transcript_job(): void
    {
        $this->insertChunk('Background summary', 1, 'This should be summarized away from the request lane.');

        $this->mock(TranscriptSummarizerService::class, function ($mock): void {
            $mock->shouldReceive('summarize')
                ->once()
                ->andReturn(['text' => '- Background summary', 'provider' => 'gemini', 'model' => 'summary-model']);
        });

        $this->withHeader('X-AITranscriber-Background', '1')
            ->postJson('/transcripts/summary', ['category_name' => 'Background summary'])
            ->assertAccepted()
            ->assertJsonPath('background', true)
            ->assertJsonStructure(['job_id', 'status_url', 'cancel_url', 'data']);

        $this->assertDatabaseHas('transcript_summaries', [
            'category_name' => 'Background summary',
            'summary_text' => '- Background summary',
            'status' => 'complete',
        ]);
    }

    public function test_ai_context_limit_returns_transcription_too_large(): void
    {
        $this->insertChunk('Long meeting', 1, 'A very long transcript.');
        $this->mock(TranscriptSummarizerService::class, function ($mock): void {
            $mock->shouldReceive('summarize')
                ->once()
                ->andThrow(new TranscriptPolisherException('Input exceeds the maximum context length.'));
        });

        $this->postJson('/transcripts/summary', [
            'category_name' => 'Long meeting',
            'source_type' => 'raw',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Input exceeds the maximum context length.');

        $this->assertDatabaseHas('transcript_summaries', [
            'category_name' => 'Long meeting',
            'status' => 'failed',
            'error_message' => 'Input exceeds the maximum context length.',
        ]);
    }

    public function test_provider_specific_invalid_response_is_returned_as_server_ai_error(): void
    {
        $this->insertChunk('Provider neutral', 1, 'Transcript text.');
        $this->mock(TranscriptSummarizerService::class, function ($mock): void {
            $mock->shouldReceive('summarize')
                ->once()
                ->andThrow(new TranscriptPolisherException('Gemini returns invalid response.'));
        });

        $this->postJson('/transcripts/summary', ['category_name' => 'Provider neutral'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Gemini returns invalid response.');
    }

    public function test_saved_legacy_provider_error_is_normalized_when_modal_reopens(): void
    {
        DB::table('transcript_summaries')->insert([
            'user_id' => 1,
            'category_name' => 'Legacy error',
            'source_type' => 'raw',
            'status' => 'failed',
            'error_message' => 'Gemini returned an invalid polishing response.',
            'run_token' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/transcripts/summary?category_name=Legacy%20error')
            ->assertOk()
            ->assertJsonPath('data.error_message', 'Gemini returned an invalid polishing response.');
    }

    public function test_empty_success_is_stored_as_a_failure_instead_of_complete(): void
    {
        $this->insertChunk('Empty result', 1, 'Transcript text.');
        $this->mock(TranscriptSummarizerService::class, function ($mock): void {
            $mock->shouldReceive('summarize')->once()->andReturn([
                'text' => '',
                'provider' => 'gemini',
                'model' => 'summary-model',
            ]);
        });

        $this->postJson('/transcripts/summary', ['category_name' => 'Empty result'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The transcription server returned a successful response without summary text.');

        $this->assertDatabaseHas('transcript_summaries', [
            'category_name' => 'Empty result',
            'status' => 'failed',
            'summary_text' => null,
        ]);
    }

    private function insertChunk(string $category, int $index, string $text): int
    {
        return DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => $category,
            'clip_index' => $index,
            'clip_start_ms' => ($index - 1) * 60000,
            'clip_end_ms' => $index * 60000,
            'range_label' => sprintf('%02d:00-%02d:00', $index - 1, $index),
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => sprintf('chunk_%05d.wav', $index),
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => $text,
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
