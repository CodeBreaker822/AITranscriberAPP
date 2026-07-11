<?php

namespace Tests\Feature;

use App\Services\AudioFileChunkerService;
use App\Services\OnlineAudioTransportService;
use App\Services\SpeechAudioFilterService;
use App\Services\SpeechToTextService;
use App\Services\StoredAudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AudioChunkNoSpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(StoredAudioService::class, function ($mock): void {
            $mock->shouldReceive('persistWav')->zeroOrMoreTimes()->andReturnUsing(
                fn (string $path, string $sessionId, int $id): array => [
                    'audio_path' => 'audio/'.$sessionId.'/'.$id.'.flac',
                    'audio_size' => filesize($path) ?: 1,
                    'audio_hash' => hash_file('sha256', $path) ?: str_repeat('0', 64),
                    'mime_type' => 'audio/flac',
                ],
            );
        });
    }

    public function test_live_chunks_without_speech_are_not_stored(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-live-nospeech-');
        file_put_contents($segmentPath, 'fake wav');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepareLiveClip')->once()->andReturn([
                'directory' => dirname($segmentPath),
                'path' => $segmentPath,
                'name' => 'live_00001.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanup')->once();
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepare')->once()->andReturn([
                'speech_detected' => false,
                'audio' => [
                    'path' => $segmentPath,
                    'name' => 'live_00001.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($segmentPath),
                    'duration_ms' => 60000,
                ],
                'vad' => [
                    'has_speech' => false,
                    'duration_ms' => 60000,
                    'speech_ms' => 0,
                    'segments' => [],
                ],
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')->never();
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'audio' => UploadedFile::fake()->create('clip.webm', 10, 'audio/webm'),
                'category_name' => 'Meeting',
                'clip_index' => 1,
                'clip_start_ms' => 300000,
                'clip_end_ms' => 360000,
                'range_label' => '05:00-06:00',
                'duration_ms' => 60000,
            ]);
        } finally {
            @unlink($segmentPath);
        }

        $response
            ->assertOk()
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.reason', 'no_speech_detected');

        $this->assertDatabaseCount('audio_chunks', 0);
    }

    public function test_live_chunks_are_transcribed_from_prepared_wav_audio(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-live-wav-');
        file_put_contents($segmentPath, 'prepared wav bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepareLiveClip')->once()->andReturn([
                'directory' => dirname($segmentPath),
                'path' => $segmentPath,
                'name' => 'live_00002.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanup')->once();
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepare')->once()->andReturn([
                'speech_detected' => true,
                'audio' => [
                    'path' => $segmentPath,
                    'name' => 'live_00002.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($segmentPath),
                    'duration_ms' => 60000,
                ],
                'vad' => [
                    'has_speech' => true,
                    'duration_ms' => 60000,
                    'speech_ms' => 1200,
                    'segments' => [
                        ['start_ms' => 0, 'end_ms' => 1200, 'start_seconds' => 0.0, 'end_seconds' => 1.2],
                    ],
                ],
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('transcribe')->once()->with($segmentPath, [
                'language_code' => 'tl',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
            ])->andReturn([
                'text' => 'Maayong buntag.',
                'timestamps' => [],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'audio' => UploadedFile::fake()->create('clip.webm', 10, 'audio/webm'),
                'category_name' => 'Meeting',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
                'range_label' => '01:00-02:00',
                'duration_ms' => 60000,
                'language_code' => 'tl',
            ]);
        } finally {
            @unlink($segmentPath);
        }

        $response
            ->assertCreated()
            ->assertJsonPath('data.translated_text', 'Maayong buntag.');

        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Meeting',
            'original_name' => 'live_00002.wav',
            'mime_type' => 'audio/flac',
            'file_size_bytes' => strlen('prepared wav bytes'),
            'translated_text' => 'Maayong buntag.',
        ]);
    }

    public function test_uploaded_sections_without_speech_are_not_stored(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-nospeech-');
        file_put_contents($segmentPath, 'fake audio');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('extractSegment')->once()->andReturn([
                'path' => $segmentPath,
                'name' => 'chunk_00005.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanupProcessedFiles')->once();
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepare')->once()->andReturn([
                'speech_detected' => false,
                'audio' => [
                    'path' => $segmentPath,
                    'name' => 'chunk_00005.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($segmentPath),
                    'duration_ms' => 60000,
                ],
                'vad' => [
                    'has_speech' => false,
                    'duration_ms' => 60000,
                    'speech_ms' => 0,
                    'segments' => [],
                ],
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')->never();
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'upload_session_id' => 'test-session',
                'category_name' => 'Meeting',
                'clip_index' => 5,
                'clip_start_ms' => 300000,
                'clip_end_ms' => 360000,
                'range_label' => '05:00-06:00',
                'duration_ms' => 60000,
            ]);
        } finally {
            @unlink($segmentPath);
        }

        $response
            ->assertOk()
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.source_type', 'upload');

        $this->assertDatabaseCount('audio_chunks', 0);
    }

    public function test_uploaded_sections_can_be_prepared_before_transcription(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-prepare-segment-');
        $speechPath = tempnam(sys_get_temp_dir(), 'aitranscriber-prepare-speech-');
        file_put_contents($segmentPath, 'prepared wav bytes');
        file_put_contents($speechPath, 'silero filtered wav bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('extractSegment')->once()->andReturn([
                'path' => $segmentPath,
                'name' => 'chunk_00003.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock) use ($speechPath): void {
            $mock->shouldReceive('prepare')->once()->andReturn([
                'speech_detected' => true,
                'audio' => [
                    'path' => $speechPath,
                    'name' => 'chunk_00003-speech.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($speechPath),
                    'duration_ms' => 42000,
                ],
                'vad' => [
                    'has_speech' => true,
                    'duration_ms' => 60000,
                    'speech_ms' => 42000,
                    'segments' => [['start_ms' => 1000, 'end_ms' => 43000]],
                ],
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')->never();
        });

        try {
            $response = $this->postJson('/audio-uploads/sections/prepare', [
                'upload_session_id' => 'prepare-test-session',
                'category_name' => 'Meeting',
                'clip_index' => 3,
                'clip_start_ms' => 120000,
                'clip_end_ms' => 180000,
                'range_label' => '02:00-03:00',
                'duration_ms' => 60000,
            ]);
        } finally {
            @unlink($segmentPath);
            @unlink($speechPath);
        }

        $response
            ->assertOk()
            ->assertJsonPath('data.prepared', true)
            ->assertJsonPath('data.speech_detected', true)
            ->assertJsonPath('data.source_name', 'chunk_00003.wav')
            ->assertJsonPath('data.prepared_name', 'chunk_00003-speech.wav')
            ->assertJsonPath('data.prepared_duration_ms', 42000);

        $this->assertDatabaseCount('audio_chunks', 0);
    }

    public function test_uploaded_offline_sections_flow_through_vad_and_return_whisper_text(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-offline-segment-');
        $speechPath = tempnam(sys_get_temp_dir(), 'aitranscriber-offline-speech-');
        file_put_contents($segmentPath, 'prepared wav bytes');
        file_put_contents($speechPath, 'silero filtered wav bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('extractSegment')->once()->andReturn([
                'path' => $segmentPath,
                'name' => 'chunk_00001.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanupSession')->once()->with('offline-test-session');
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock) use ($speechPath): void {
            $mock->shouldReceive('prepare')->once()->andReturn([
                'speech_detected' => true,
                'audio' => [
                    'path' => $speechPath,
                    'name' => 'chunk_00001-speech.wav',
                    'mime_type' => 'audio/wav',
                    'size' => filesize($speechPath),
                    'duration_ms' => 60000,
                ],
                'vad' => [
                    'has_speech' => true,
                    'duration_ms' => 60000,
                    'speech_ms' => 60000,
                    'segments' => [['start_ms' => 0, 'end_ms' => 60000]],
                ],
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($speechPath): void {
            $mock->shouldReceive('transcribe')->once()->with($speechPath, [
                'language_code' => 'auto',
                'clip_index' => 1,
                'clip_start_ms' => 0,
                'clip_end_ms' => 60000,
                'engine' => 'offline',
                'model' => 'tiny',
                'release_worker' => true,
            ])->andReturn([
                'text' => 'Offline Whisper transcript.',
                'timestamps' => [['text' => 'Offline Whisper transcript.', 'start' => 0, 'end' => 2]],
                'provider' => 'whisper.cpp',
                'model' => 'tiny-q8_0',
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'upload_session_id' => 'offline-test-session',
                'category_name' => 'Offline meeting',
                'clip_index' => 1,
                'clip_start_ms' => 0,
                'clip_end_ms' => 60000,
                'range_label' => '00:00-01:00',
                'duration_ms' => 60000,
                'language_code' => 'auto',
                'transcription_engine' => 'offline',
                'whisper_model' => 'tiny',
                'finalize_session' => true,
            ]);
        } finally {
            @unlink($segmentPath);
            @unlink($speechPath);
        }

        $response
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'upload')
            ->assertJsonPath('data.translated_text', 'Offline Whisper transcript.')
            ->assertJsonPath('data.transcription_timestamps.0.text', 'Offline Whisper transcript.');

        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Offline meeting',
            'original_name' => 'chunk_00001-speech.wav',
            'translated_text' => 'Offline Whisper transcript.',
            'status' => 'transcribed',
        ]);
    }

    public function test_prepared_uploaded_sections_skip_repeated_vad_before_transcription(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-prepared-source-');
        $speechPath = tempnam(sys_get_temp_dir(), 'aitranscriber-prepared-speech-');
        file_put_contents($segmentPath, 'prepared wav bytes');
        file_put_contents($speechPath, 'silero filtered wav bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath, $speechPath): void {
            $mock->shouldReceive('extractSegment')->never();
            $mock->shouldReceive('sessionAudioFile')->once()->with('prepared-test-session', 'chunk_00002-speech.wav')->andReturn([
                'path' => $speechPath,
                'name' => 'chunk_00002-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechPath),
                'duration_ms' => 45000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('prepared-test-session', 'chunk_00002.wav')->andReturn([
                'path' => $segmentPath,
                'name' => 'chunk_00002.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanupProcessedFiles')->once();
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock): void {
            $mock->shouldReceive('prepare')->never();
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($speechPath): void {
            $mock->shouldReceive('transcribe')->once()->with($speechPath, [
                'language_code' => 'en',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
                'engine' => 'online',
                'model' => 'tiny',
                'release_worker' => false,
            ])->andReturn([
                'text' => 'Prepared transcript.',
                'timestamps' => [],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'upload_session_id' => 'prepared-test-session',
                'category_name' => 'Prepared meeting',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
                'range_label' => '01:00-02:00',
                'duration_ms' => 60000,
                'language_code' => 'en',
                'transcription_engine' => 'online',
                'whisper_model' => 'tiny',
                'source_name' => 'chunk_00002.wav',
                'prepared_name' => 'chunk_00002-speech.wav',
            ]);
        } finally {
            @unlink($segmentPath);
            @unlink($speechPath);
        }

        $response
            ->assertCreated()
            ->assertJsonPath('data.translated_text', 'Prepared transcript.');

        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Prepared meeting',
            'original_name' => 'chunk_00002-speech.wav',
            'translated_text' => 'Prepared transcript.',
        ]);
    }

    public function test_prepared_uploaded_sections_can_be_stored_as_a_transcription_batch(): void
    {
        Queue::fake();
        $sourceOnePath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-source-a-');
        $speechOnePath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-speech-a-');
        $sourceTwoPath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-source-b-');
        $speechTwoPath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-speech-b-');
        file_put_contents($sourceOnePath, 'source one bytes');
        file_put_contents($speechOnePath, 'speech one bytes');
        file_put_contents($sourceTwoPath, 'source two bytes');
        file_put_contents($speechTwoPath, 'speech two bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($sourceOnePath, $speechOnePath, $sourceTwoPath, $speechTwoPath): void {
            $mock->shouldReceive('sessionAudioFile')->once()->with('batch-test-session', 'chunk_00001.wav')->andReturn([
                'path' => $sourceOnePath,
                'name' => 'chunk_00001.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($sourceOnePath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('batch-test-session', 'chunk_00001-speech.wav')->andReturn([
                'path' => $speechOnePath,
                'name' => 'chunk_00001-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechOnePath),
                'duration_ms' => 280000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('batch-test-session', 'chunk_00002.wav')->andReturn([
                'path' => $sourceTwoPath,
                'name' => 'chunk_00002.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($sourceTwoPath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('batch-test-session', 'chunk_00002-speech.wav')->andReturn([
                'path' => $speechTwoPath,
                'name' => 'chunk_00002-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechTwoPath),
                'duration_ms' => 275000,
            ]);
            $mock->shouldReceive('cleanupProcessedFiles')->once();
        });

        $this->mock(SpeechAudioFilterService::class, function ($mock): void {
            $mock->shouldReceive('prepare')->never();
        });

        $this->mock(OnlineAudioTransportService::class, function ($mock) use ($speechOnePath, $speechTwoPath): void {
            $mock->shouldReceive('fromPreparedWav')->once()->withArgs(
                fn (array $audio): bool => ($audio['path'] ?? null) === $speechOnePath,
            )->andReturn([
                'path' => $speechOnePath,
                'name' => 'chunk_00001-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechOnePath),
                'duration_ms' => 280000,
            ]);
            $mock->shouldReceive('fromPreparedWav')->once()->withArgs(
                fn (array $audio): bool => ($audio['path'] ?? null) === $speechTwoPath,
            )->andReturn([
                'path' => $speechTwoPath,
                'name' => 'chunk_00002-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechTwoPath),
                'duration_ms' => 275000,
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($speechOnePath, $speechTwoPath): void {
            $mock->shouldReceive('transcribeBatch')->once()->with([
                ['audio' => $speechOnePath, 'clip_index' => 1, 'clip_start_ms' => 0, 'clip_end_ms' => 300000],
                ['audio' => $speechTwoPath, 'clip_index' => 2, 'clip_start_ms' => 300000, 'clip_end_ms' => 600000],
            ], [
                'language_code' => 'en',
                'engine' => 'online',
                'model' => 'tiny',
                'release_worker' => true,
            ])->andReturn([
                [
                    'clip_index' => 1,
                    'queue_index' => 0,
                    'text' => 'Batch first transcript.',
                    'timestamps' => [['text' => 'Batch first transcript.', 'start' => 0, 'end' => 2]],
                ],
                [
                    'clip_index' => 2,
                    'queue_index' => 1,
                    'text' => 'Batch second transcript.',
                    'timestamps' => [['text' => 'Batch second transcript.', 'start' => 300, 'end' => 302]],
                ],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks/batch', [
                'upload_session_id' => 'batch-test-session',
                'category_name' => 'Batch meeting',
                'language_code' => 'en',
                'transcription_engine' => 'online',
                'whisper_model' => 'tiny',
                'speaker_session_id' => 'batch-test-session',
                'finalize_session' => true,
                'sections' => [
                    [
                        'clip_index' => 1,
                        'clip_start_ms' => 0,
                        'clip_end_ms' => 300000,
                        'range_label' => '00:00-05:00',
                        'duration_ms' => 300000,
                        'source_name' => 'chunk_00001.wav',
                        'prepared_name' => 'chunk_00001-speech.wav',
                    ],
                    [
                        'clip_index' => 2,
                        'clip_start_ms' => 300000,
                        'clip_end_ms' => 600000,
                        'range_label' => '05:00-10:00',
                        'duration_ms' => 300000,
                        'source_name' => 'chunk_00002.wav',
                        'prepared_name' => 'chunk_00002-speech.wav',
                    ],
                ],
            ]);
        } finally {
            @unlink($sourceOnePath);
            @unlink($speechOnePath);
            @unlink($sourceTwoPath);
            @unlink($speechTwoPath);
        }

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.translated_text', 'Batch first transcript.')
            ->assertJsonPath('data.1.translated_text', 'Batch second transcript.');

        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Batch meeting',
            'original_name' => 'chunk_00001-speech.wav',
            'translated_text' => 'Batch first transcript.',
        ]);
        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Batch meeting',
            'original_name' => 'chunk_00002-speech.wav',
            'translated_text' => 'Batch second transcript.',
        ]);
    }

    public function test_later_upload_batches_send_batch_relative_timings_to_the_transcription_server(): void
    {
        Queue::fake();
        $sourceFivePath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-source-e-');
        $speechFivePath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-speech-e-');
        $sourceSixPath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-source-f-');
        $speechSixPath = tempnam(sys_get_temp_dir(), 'aitranscriber-batch-speech-f-');
        file_put_contents($sourceFivePath, 'source five bytes');
        file_put_contents($speechFivePath, 'speech five bytes');
        file_put_contents($sourceSixPath, 'source six bytes');
        file_put_contents($speechSixPath, 'speech six bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($sourceFivePath, $speechFivePath, $sourceSixPath, $speechSixPath): void {
            $mock->shouldReceive('sessionAudioFile')->once()->with('later-batch-session', 'chunk_00005.wav')->andReturn([
                'path' => $sourceFivePath,
                'name' => 'chunk_00005.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($sourceFivePath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('later-batch-session', 'chunk_00005-speech.wav')->andReturn([
                'path' => $speechFivePath,
                'name' => 'chunk_00005-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechFivePath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('later-batch-session', 'chunk_00006.wav')->andReturn([
                'path' => $sourceSixPath,
                'name' => 'chunk_00006.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($sourceSixPath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('sessionAudioFile')->once()->with('later-batch-session', 'chunk_00006-speech.wav')->andReturn([
                'path' => $speechSixPath,
                'name' => 'chunk_00006-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechSixPath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('cleanupProcessedFiles')->once();
        });

        $this->mock(OnlineAudioTransportService::class, function ($mock) use ($speechFivePath, $speechSixPath): void {
            $mock->shouldReceive('fromPreparedWav')->once()->withArgs(
                fn (array $audio): bool => ($audio['path'] ?? null) === $speechFivePath,
            )->andReturn([
                'path' => $speechFivePath,
                'name' => 'chunk_00005-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechFivePath),
                'duration_ms' => 300000,
            ]);
            $mock->shouldReceive('fromPreparedWav')->once()->withArgs(
                fn (array $audio): bool => ($audio['path'] ?? null) === $speechSixPath,
            )->andReturn([
                'path' => $speechSixPath,
                'name' => 'chunk_00006-speech.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($speechSixPath),
                'duration_ms' => 300000,
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($speechFivePath, $speechSixPath): void {
            $mock->shouldReceive('transcribeBatch')->once()->with([
                ['audio' => $speechFivePath, 'clip_index' => 5, 'clip_start_ms' => 0, 'clip_end_ms' => 300000],
                ['audio' => $speechSixPath, 'clip_index' => 6, 'clip_start_ms' => 300000, 'clip_end_ms' => 600000],
            ], [
                'language_code' => 'en',
                'engine' => 'online',
                'model' => 'tiny',
                'release_worker' => true,
            ])->andReturn([
                [
                    'clip_index' => 5,
                    'queue_index' => 0,
                    'text' => 'Later batch first transcript.',
                    'timestamps' => [],
                ],
                [
                    'clip_index' => 6,
                    'queue_index' => 1,
                    'text' => 'Later batch second transcript.',
                    'timestamps' => [],
                ],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks/batch', [
                'upload_session_id' => 'later-batch-session',
                'category_name' => 'Later batch meeting',
                'language_code' => 'en',
                'transcription_engine' => 'online',
                'whisper_model' => 'tiny',
                'speaker_session_id' => 'later-batch-session',
                'finalize_session' => true,
                'sections' => [
                    [
                        'clip_index' => 5,
                        'clip_start_ms' => 1200000,
                        'clip_end_ms' => 1500000,
                        'range_label' => '20:00-25:00',
                        'duration_ms' => 300000,
                        'source_name' => 'chunk_00005.wav',
                        'prepared_name' => 'chunk_00005-speech.wav',
                    ],
                    [
                        'clip_index' => 6,
                        'clip_start_ms' => 1500000,
                        'clip_end_ms' => 1800000,
                        'range_label' => '25:00-30:00',
                        'duration_ms' => 300000,
                        'source_name' => 'chunk_00006.wav',
                        'prepared_name' => 'chunk_00006-speech.wav',
                    ],
                ],
            ]);
        } finally {
            @unlink($sourceFivePath);
            @unlink($speechFivePath);
            @unlink($sourceSixPath);
            @unlink($speechSixPath);
        }

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.translated_text', 'Later batch first transcript.')
            ->assertJsonPath('data.1.translated_text', 'Later batch second transcript.');

        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Later batch meeting',
            'clip_index' => 5,
            'clip_start_ms' => 1200000,
            'clip_end_ms' => 1500000,
            'translated_text' => 'Later batch first transcript.',
        ]);
    }

    public function test_loading_chunks_deletes_existing_no_speech_rows(): void
    {
        DB::table('audio_chunks')->insert([
            [
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
                'translated_text' => 'No speech detected.',
                'transcription_timestamps' => json_encode([]),
                'status' => 'transcribed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 1,
                'category_name' => 'Meeting',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
                'range_label' => '01:00-02:00',
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
            ],
        ]);

        $response = $this->getJson('/audio-chunks');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.translated_text', 'Proceed to the next agenda.');

        $this->assertDatabaseMissing('audio_chunks', [
            'translated_text' => 'No speech detected.',
        ]);
    }
}
