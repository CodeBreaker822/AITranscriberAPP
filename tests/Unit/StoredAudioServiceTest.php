<?php

namespace Tests\Unit;

use App\Services\StoredAudioService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StoredAudioServiceTest extends TestCase
{
    public function test_it_compresses_wav_to_filesystem_flac_with_metadata(): void
    {
        $ffmpeg = new Process([base_path('ffmpeg/bin/ffmpeg.exe'), '-version']);
        $ffmpeg->run();

        if (! $ffmpeg->isSuccessful()) {
            $this->markTestSkipped('Bundled FFmpeg is unavailable in this test environment.');
        }

        $wavPath = storage_path('framework/testing/stored-audio-source.wav');
        File::ensureDirectoryExists(dirname($wavPath));
        file_put_contents($wavPath, $this->silentWav());
        $service = app(StoredAudioService::class);

        try {
            $metadata = $service->persistWav($wavPath, 'storage-test-session', 987654);
            $storedPath = $service->absolutePath($metadata['audio_path']);

            $this->assertNotNull($storedPath);
            $this->assertSame('audio/flac', $metadata['mime_type']);
            $this->assertSame(filesize($storedPath), $metadata['audio_size']);
            $this->assertSame(hash_file('sha256', $storedPath), $metadata['audio_hash']);
            $this->assertLessThan(filesize($wavPath), filesize($storedPath));
        } finally {
            $service->delete($metadata['audio_path'] ?? null);
            @unlink($wavPath);
        }
    }

    private function silentWav(): string
    {
        $sampleCount = 16000;
        $pcm = str_repeat("\0", $sampleCount * 2);
        $dataSize = strlen($pcm);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', $dataSize).$pcm;
    }
}
