<?php

namespace Tests\Feature;

use App\Services\Audio\AudioFileChunkerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioFileChunkerServiceTest extends TestCase
{
    public function test_success_cleanup_removes_generated_chunks_then_the_completed_session(): void
    {
        $sessionId = 'cleanup-'.uniqid();
        $directory = storage_path('app/private/audio-upload-sessions/'.$sessionId);
        $outside = storage_path('framework/testing/do-not-delete-'.uniqid().'.wav');
        File::ensureDirectoryExists($directory);
        File::ensureDirectoryExists(dirname($outside));
        $source = $directory.DIRECTORY_SEPARATOR.'source.wav';
        $chunk = $directory.DIRECTORY_SEPARATOR.'chunk_00001.wav';
        $speech = $directory.DIRECTORY_SEPARATOR.'chunk_00001-speech.wav';
        file_put_contents($source, 'source');
        file_put_contents($chunk, 'chunk');
        file_put_contents($speech, 'speech');
        file_put_contents($directory.DIRECTORY_SEPARATOR.'session.json', '{}');
        file_put_contents($outside, 'outside');

        try {
            $chunker = app(AudioFileChunkerService::class);
            $chunker->cleanupProcessedFiles(
                ['path' => $chunk],
                ['path' => $speech],
                ['path' => $source],
                ['path' => $outside],
            );

            $this->assertFileDoesNotExist($chunk);
            $this->assertFileDoesNotExist($speech);
            $this->assertFileExists($source);
            $this->assertFileExists($outside);

            $chunker->cleanupSession($sessionId);

            $this->assertDirectoryDoesNotExist($directory);
            $this->assertFileExists($outside);
        } finally {
            File::deleteDirectory($directory);
            File::delete($outside);
        }
    }

    public function test_it_extracts_only_the_requested_audio_segment(): void
    {
        $ffmpegPath = base_path('ffmpeg/bin/ffmpeg.exe');

        if (! is_file($ffmpegPath)) {
            $this->markTestSkipped('Bundled ffmpeg binary is not available.');
        }

        $samplePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aitranscriber-chunker-test-'.uniqid().'.wav';
        $process = new Process([
            $ffmpegPath,
            '-y',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=125',
            '-ac',
            '1',
            '-ar',
            '16000',
            $samplePath,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $sourceSize = filesize($samplePath);
        $chunker = app(AudioFileChunkerService::class);
        $file = new UploadedFile($samplePath, 'aitranscriber-chunker-test.wav', 'audio/wav', null, true);
        $session = $chunker->createSession($file);
        $sections = $chunker->buildSections($session['duration_ms'], 60);
        $segment = $chunker->extractSegment(
            $session['session_id'],
            $sections[0]['index'],
            $sections[0]['start_ms'],
            $sections[0]['duration_ms'],
        );

        $this->assertSame(3, count($sections));
        $this->assertGreaterThanOrEqual(59000, $segment['duration_ms']);
        $this->assertLessThanOrEqual(61000, $segment['duration_ms']);
        $this->assertLessThan($sourceSize, $segment['size']);
    }

    public function test_it_can_extract_from_a_local_path_without_copying_the_source(): void
    {
        $ffmpegPath = base_path('ffmpeg/bin/ffmpeg.exe');

        if (! is_file($ffmpegPath)) {
            $this->markTestSkipped('Bundled ffmpeg binary is not available.');
        }

        $samplePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aitranscriber-local-path-test-'.uniqid().'.wav';
        $process = new Process([
            $ffmpegPath,
            '-y',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=65',
            '-ac',
            '1',
            '-ar',
            '16000',
            $samplePath,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $chunker = app(AudioFileChunkerService::class);
        $session = $chunker->createSessionFromPath($samplePath);
        $sections = $chunker->buildSections($session['duration_ms'], 60);
        $sessionDirectoryFiles = scandir($session['directory']) ?: [];

        $this->assertFileExists($samplePath);
        $this->assertSame(realpath($samplePath), $session['source_path']);
        $this->assertNotContains('source.wav', $sessionDirectoryFiles);

        $segment = $chunker->extractSegment(
            $session['session_id'],
            $sections[0]['index'],
            $sections[0]['start_ms'],
            $sections[0]['duration_ms'],
        );

        $this->assertFileExists($samplePath);
        $this->assertGreaterThanOrEqual(59000, $segment['duration_ms']);

        @unlink($samplePath);
    }
}
