<?php

namespace Tests\Feature;

use App\Services\Audio\AudioFileChunkerService;
use App\Services\Audio\SpeechAudioFilterService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UploadedAudioTranscriptionControllerTest extends TestCase
{
    public function test_upload_preparation_accepts_and_prepares_two_sections_like_the_ui(): void
    {
        config(['services.audio.chunk_seconds' => 1]);
        $path = $this->makeSilentWav(2);

        try {
            $upload = $this->withHeaders(['Accept' => 'application/json'])->post('/audio-uploads', [
                'audio_file' => new UploadedFile($path, 'sample-two-sections.wav', 'audio/wav', null, true),
                'chunk_seconds' => 1,
            ]);

            $upload
                ->assertCreated()
                ->assertJsonPath('message', 'ready')
                ->assertJsonPath('data.count', 2)
                ->assertJsonPath('data.sections.0.index', 1)
                ->assertJsonPath('data.sections.1.index', 2);

            $sessionId = (string) $upload->json('data.session_id');
            $sections = $upload->json('data.sections');

            $this->mock(SpeechAudioFilterService::class, function ($mock): void {
                $mock->shouldReceive('prepare')->twice()->andReturnUsing(fn (array $audio, array $context): array => [
                    'speech_detected' => false,
                    'audio' => $audio,
                    'vad' => [
                        'has_speech' => false,
                        'duration_ms' => (int) $audio['duration_ms'],
                        'speech_ms' => 0,
                        'segments' => [],
                    ],
                ]);
            });

            $this->postJson('/audio-uploads/sections/prepare-batch', [
                'upload_session_id' => $sessionId,
                'category_name' => 'User workflow',
                'concurrency' => 2,
                'sections' => array_map(fn (array $section): array => [
                    'clip_index' => $section['index'],
                    'clip_start_ms' => $section['start_ms'],
                    'clip_end_ms' => $section['end_ms'],
                    'duration_ms' => $section['duration_ms'],
                    'range_label' => $section['range_label'],
                ], $sections),
            ])
                ->assertOk()
                ->assertJsonPath('message', 'prepared')
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.prepared', true)
                ->assertJsonPath('data.0.prepared_skipped', true)
                ->assertJsonPath('data.1.prepared', true)
                ->assertJsonPath('data.1.prepared_skipped', true);
        } finally {
            if (isset($sessionId) && $sessionId !== '') {
                app(AudioFileChunkerService::class)->cleanupSession($sessionId);
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function makeSilentWav(int $seconds): string
    {
        $ffmpeg = base_path('ffmpeg/bin/ffmpeg.exe');

        if (! is_file($ffmpeg)) {
            $this->markTestSkipped('Bundled ffmpeg.exe is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'aitranscriber-upload-').'.wav';
        $process = new Process([
            $ffmpeg,
            '-y',
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=16000:cl=mono',
            '-t',
            (string) $seconds,
            '-c:a',
            'pcm_s16le',
            $path,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path)) {
            $this->fail('Could not create test WAV file: '.trim($process->getErrorOutput()));
        }

        return $path;
    }
}
