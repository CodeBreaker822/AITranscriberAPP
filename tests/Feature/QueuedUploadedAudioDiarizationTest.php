<?php

namespace Tests\Feature;

use App\Enums\AudioChunkStatus;
use App\Jobs\DiarizeUploadedAudioBatch;
use App\Services\AudioFileChunkerService;
use App\Services\SpeakerDiarizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class QueuedUploadedAudioDiarizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_merges_only_the_audio_chunks_named_in_its_queue_payload(): void
    {
        $queuedId = $this->insertAudioChunk(1, 'Raw queued transcript', 'diarization_queued');
        $untouchedId = $this->insertAudioChunk(2, 'Another raw transcript', 'diarization_queued');
        $directory = storage_path('app/private/audio-upload-sessions/upload-session-1');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $audioPath = $directory.'/chunk_00001-speech.wav';
        file_put_contents($audioPath, str_repeat("\0", 48));
        $diarization = Mockery::mock(SpeakerDiarizationService::class);
        $diarization->shouldReceive('apply')
            ->once()
            ->withArgs(fn (string $audioPath, array $transcription, array $options): bool => is_file($audioPath)
                && $transcription['text'] === 'Raw queued transcript'
                && $options['clip_start_ms'] === 0
                && $options['speaker_session_id'] === 'upload-session-1'
                && $options['throw_on_failure'] === true)
            ->andReturn([
                'text' => 'Speaker 1: Raw queued transcript',
                'timestamps' => [['text' => 'Raw queued transcript', 'start' => 0, 'end' => 1, 'speaker_id' => 'speaker_1']],
            ]);
        $diarization->shouldReceive('releaseSession')->once()->with('upload-session-1');
        $chunker = Mockery::mock(AudioFileChunkerService::class);
        $chunker->shouldReceive('cleanupSession')->once()->with('upload-session-1');

        (new DiarizeUploadedAudioBatch(
            [$queuedId => $audioPath],
            'upload-session-1',
            'upload-session-1',
            true,
        ))->handle($diarization, $chunker);

        $queued = DB::table('audio_chunks')->where('id', $queuedId)->first();
        $untouched = DB::table('audio_chunks')->where('id', $untouchedId)->first();

        $this->assertSame('Speaker 1: Raw queued transcript', $queued->translated_text);
        $this->assertSame(AudioChunkStatus::Transcribed->value, $queued->status);
        $this->assertSame('Another raw transcript', $untouched->translated_text);
        $this->assertSame(AudioChunkStatus::DiarizationQueued->value, $untouched->status);
        $this->assertFileDoesNotExist($audioPath);
    }

    public function test_exhausted_diarization_is_marked_failed_and_retained_audio_is_cleaned(): void
    {
        $audioChunkId = $this->insertAudioChunk(3, 'Retry transcript', 'diarization_queued');
        $directory = storage_path('app/private/audio-upload-sessions/retry-session');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $audioPath = $directory.'/chunk_00003-speech.wav';
        file_put_contents($audioPath, str_repeat("\0", 48));
        $diarization = Mockery::mock(SpeakerDiarizationService::class);
        $diarization->shouldReceive('apply')->once()->andThrow(new \RuntimeException('Sherpa failed'));
        $chunker = Mockery::mock(AudioFileChunkerService::class);
        $job = new DiarizeUploadedAudioBatch(
            [$audioChunkId => $audioPath],
            'retry-session',
            'retry-session',
        );

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 15, 30], $job->backoff);

        try {
            $job->handle($diarization, $chunker);
            $this->fail('Expected the failed Sherpa attempt to be retried.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Sherpa failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('audio_chunks', [
            'id' => $audioChunkId,
            'status' => 'diarization_retrying',
        ]);

        $job->failed(new \RuntimeException('Sherpa failed'));

        $this->assertDatabaseHas('audio_chunks', [
            'id' => $audioChunkId,
            'status' => 'diarization_failed',
        ]);
        $this->assertFileDoesNotExist($audioPath);
    }

    public function test_upload_session_finalization_does_not_requeue_empty_polling_jobs(): void
    {
        Queue::fake();
        $audioChunkId = $this->insertAudioChunk(4, 'Still pending', 'diarization_processing');
        DB::table('audio_chunks')->where('id', $audioChunkId)->update([
            'audio_path' => 'audio/bounded-session/chunk_00004-speech.wav',
        ]);
        $diarization = Mockery::mock(SpeakerDiarizationService::class);
        $diarization->shouldNotReceive('releaseSession');
        $chunker = Mockery::mock(AudioFileChunkerService::class);
        $chunker->shouldNotReceive('cleanupSession');

        (new DiarizeUploadedAudioBatch(
            [],
            'bounded-session',
            'bounded-session',
            true,
        ))->handle($diarization, $chunker);

        Queue::assertNotPushed(DiarizeUploadedAudioBatch::class);
    }

    public function test_failed_empty_finalizer_does_not_start_a_new_finalize_loop(): void
    {
        Queue::fake();
        $audioChunkId = $this->insertAudioChunk(5, 'Stuck pending', 'diarization_waiting_transcript');
        DB::table('audio_chunks')->where('id', $audioChunkId)->update([
            'audio_path' => 'audio/stuck-session/chunk_00005-speech.wav',
        ]);

        (new DiarizeUploadedAudioBatch(
            [],
            'stuck-session',
            'stuck-session',
            true,
        ))->failed(new \RuntimeException('empty finalizer exceeded attempts'));

        Queue::assertNotPushed(DiarizeUploadedAudioBatch::class);
        $this->assertDatabaseHas('audio_chunks', [
            'id' => $audioChunkId,
            'status' => 'diarization_waiting_transcript',
        ]);
    }

    private function insertAudioChunk(int $clipIndex, string $text, string $status): int
    {
        return DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Queued upload',
            'clip_index' => $clipIndex,
            'clip_start_ms' => ($clipIndex - 1) * 1000,
            'clip_end_ms' => $clipIndex * 1000,
            'range_label' => '00:00-00:01',
            'duration_ms' => 1000,
            'mime_type' => 'audio/wav',
            'original_name' => "chunk_{$clipIndex}-speech.wav",
            'file_size_bytes' => 48,
            'audio_blob' => str_repeat("\0", 48),
            'translated_text' => $text,
            'transcription_timestamps' => json_encode([['text' => $text, 'start' => 0, 'end' => 1]]),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
