<?php

namespace Tests\Feature;

use App\Enums\AudioChunkStatus;
use App\Jobs\DiarizeUploadedAudioBatch;
use App\Services\AudioFileChunkerService;
use App\Services\SpeakerDiarizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function test_online_upload_uses_a_durable_queue_without_changing_live_or_offline_diarization(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/AudioChunkController.php');
        $facade = file_get_contents($root.'/app/Services/AudioChunk/AudioChunkIngestionService.php');
        $liveIngestion = file_get_contents($root.'/app/Services/AudioChunk/LiveAudioIngestion.php');
        $sectionIngestion = file_get_contents($root.'/app/Services/AudioChunk/UploadedSectionIngestion.php');
        $batchIngestion = file_get_contents($root.'/app/Services/AudioChunk/UploadedBatchIngestion.php');
        $frontend = file_get_contents($root.'/resources/js/app.js');
        $speechToText = file_get_contents($root.'/app/Services/SpeechToTextService.php');
        $tauri = file_get_contents($root.'/src-tauri/src/main.rs');

        $this->assertStringContainsString('LiveAudioIngestion', $facade);
        $this->assertStringContainsString('UploadedSectionIngestion', $facade);
        $this->assertStringContainsString('UploadedBatchIngestion', $facade);
        $this->assertStringContainsString('DiarizeUploadedAudioBatch::dispatch(', $batchIngestion);
        $this->assertStringContainsString('$queuedItems->each(function (string $audioPath, int $audioChunkId)', $batchIngestion);
        $this->assertStringContainsString('$finalizeSession && $audioChunkId === $lastQueuedId', $batchIngestion);
        $this->assertStringContainsString('AudioChunkStatus::DiarizationQueued->value', $batchIngestion);
        $this->assertStringContainsString('AudioChunkStatus::Transcribed->value', $batchIngestion);
        $this->assertStringNotContainsString('diarizationBatchProcess(', $controller);
        $this->assertStringContainsString('Rule::in([TranscriptionEngine::Online->value])', $controller);
        $this->assertGreaterThanOrEqual(2, substr_count($liveIngestion.$sectionIngestion, '$this->speakerDiarization->apply('));
        $this->assertStringContainsString("if (audioChunkBatchUrl && getTranscriptionEngine() === 'online')", $frontend);
        $this->assertStringNotContainsString('$this->offlineWhisper->transcribe($clip', $speechToText);
        $this->assertStringContainsString('return $this->api->transcribeBatch($clips, $options);', $speechToText);
        $this->assertStringContainsString('restartQueuedDiarizationMonitor(queuedDiarizationIds);', $frontend);
        $this->assertStringContainsString('data-audio-chunk-status-url', file_get_contents($root.'/resources/views/components/app-layout.blade.php'));
        preg_match('/const monitorQueuedDiarization = .*?^        \};/ms', $frontend, $monitorMatch);
        $monitor = $monitorMatch[0] ?? '';
        $this->assertNotSame('', $monitor);
        $this->assertStringContainsString('$.getJSON(audioChunkStatusUrl, { ids: pendingIds })', $monitor);
        $this->assertStringNotContainsString('$.getJSON(storedUrl)', $monitor);
        $this->assertStringContainsString('uploadSessionDiarizationFinished()', file_get_contents($root.'/app/Jobs/DiarizeUploadedAudioBatch.php'));
        $this->assertStringContainsString('.arg("queue:work")', $tauri);
        $this->assertStringContainsString('.arg("--tries=3")', $tauri);
        $this->assertStringContainsString('queue_worker: Mutex<Option<Child>>', $tauri);
    }

    public function test_prepared_transcription_merge_lives_in_ingestion_not_persistence(): void
    {
        $root = dirname(__DIR__, 2);
        $completion = file_get_contents($root.'/app/Services/AudioChunk/PreparedAudioCompletionService.php');
        $persistence = file_get_contents($root.'/app/Services/AudioChunk/AudioChunkPersistenceService.php');

        $this->assertStringContainsString('private function preparedCompletionResult(', $completion);
        $this->assertStringContainsString('$this->uploadedDiarization->mergePreparedResultIfAvailable(', $completion);
        $this->assertStringContainsString('$this->uploadedDiarization->hasPreparedResult(', $completion);
        $this->assertStringContainsString('public function complete(', $completion);
        $this->assertStringContainsString('string $finalStatus', $persistence);
        $this->assertStringNotContainsString('UploadedDiarizationService', $persistence);
        $this->assertStringNotContainsString('mergePreparedResultIfAvailable', $persistence);
        $this->assertStringNotContainsString('hasPreparedResult', $persistence);
    }

    public function test_ingestion_uses_shared_lifecycle_helpers_for_cleanup_and_release(): void
    {
        $root = dirname(__DIR__, 2);
        $liveIngestion = file_get_contents($root.'/app/Services/AudioChunk/LiveAudioIngestion.php');
        $sectionIngestion = file_get_contents($root.'/app/Services/AudioChunk/UploadedSectionIngestion.php');
        $batchIngestion = file_get_contents($root.'/app/Services/AudioChunk/UploadedBatchIngestion.php');

        $this->assertStringContainsString('private function releaseFinalizedSession(', $liveIngestion);
        $this->assertStringContainsString('private function cleanupPreparedClip(', $liveIngestion);
        $this->assertStringContainsString('private function releaseFinalizedSession(', $sectionIngestion);
        $this->assertStringContainsString('private function cleanupUploadedSectionFiles(', $sectionIngestion);
        $this->assertStringContainsString('private function cleanupUploadedSectionFiles(', $batchIngestion);
        $this->assertGreaterThanOrEqual(3, substr_count($liveIngestion.$sectionIngestion, '$this->releaseFinalizedSession('));
        $this->assertGreaterThanOrEqual(5, substr_count($liveIngestion, '$this->cleanupPreparedClip('));
        $this->assertGreaterThanOrEqual(4, substr_count($sectionIngestion.$batchIngestion, '$this->cleanupUploadedSectionFiles('));
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

    public function test_upload_session_finalization_requeues_with_a_bounded_attempt_counter(): void
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
            6,
        ))->handle($diarization, $chunker);

        Queue::assertPushed(DiarizeUploadedAudioBatch::class, function (DiarizeUploadedAudioBatch $job): bool {
            return $job->audioChunkPaths === []
                && $job->speakerSessionId === 'bounded-session'
                && $job->uploadSessionId === 'bounded-session'
                && $job->finalizeSession === true
                && $job->finalizeAttempts === 7;
        });
    }

    public function test_upload_session_finalization_stops_after_the_retry_limit(): void
    {
        Queue::fake();
        Log::spy();
        $audioChunkId = $this->insertAudioChunk(5, 'Stuck pending', 'diarization_waiting_transcript');
        DB::table('audio_chunks')->where('id', $audioChunkId)->update([
            'audio_path' => 'audio/stuck-session/chunk_00005-speech.wav',
        ]);
        $diarization = Mockery::mock(SpeakerDiarizationService::class);
        $diarization->shouldNotReceive('releaseSession');
        $chunker = Mockery::mock(AudioFileChunkerService::class);
        $chunker->shouldNotReceive('cleanupSession');

        (new DiarizeUploadedAudioBatch(
            [],
            'stuck-session',
            'stuck-session',
            true,
            DiarizeUploadedAudioBatch::MAX_FINALIZE_ATTEMPTS,
        ))->handle($diarization, $chunker);

        Queue::assertNotPushed(DiarizeUploadedAudioBatch::class);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Queued speaker diarization finalization stopped before cleanup because pending chunks did not reach a terminal status.'
                && $context['speaker_session_id'] === 'stuck-session'
                && $context['upload_session_id'] === 'stuck-session'
                && $context['finalize_attempts'] === DiarizeUploadedAudioBatch::MAX_FINALIZE_ATTEMPTS
                && ($context['pending_status_counts']['diarization_waiting_transcript'] ?? 0) === 1
        )->once();
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
