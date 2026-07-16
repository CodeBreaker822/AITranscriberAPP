<?php

namespace Tests\Unit;

use App\Services\Audio\PassthroughSpeechActivityDetector;
use App\Services\Audio\SileroVadService;
use App\Services\Audio\SpeechActivityDetectorResolver;
use App\Services\Audio\SpeechAudioFilterService;
use Tests\TestCase;

class SpeechActivityDetectorResolverTest extends TestCase
{
    public function test_it_selects_vad_detector_like_a_replaceable_block(): void
    {
        $resolver = app(SpeechActivityDetectorResolver::class);

        $this->assertInstanceOf(SileroVadService::class, $resolver->detector('silero'));
        $this->assertInstanceOf(PassthroughSpeechActivityDetector::class, $resolver->detector('disabled'));
        $this->assertInstanceOf(PassthroughSpeechActivityDetector::class, $resolver->detector('off'));
        $this->assertInstanceOf(PassthroughSpeechActivityDetector::class, $resolver->detector('none'));
    }

    public function test_disabled_vad_passes_audio_through_without_filtering(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aitranscriber-vad-disabled-');
        file_put_contents($path, 'fake wav bytes');

        try {
            $result = app(SpeechAudioFilterService::class)->prepare([
                'path' => $path,
                'name' => 'chunk_00001.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($path),
                'duration_ms' => 60000,
            ], [
                'vad_driver' => 'disabled',
                'category_name' => 'No VAD',
                'source_type' => 'upload',
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['speech_detected']);
        $this->assertSame('chunk_00001.wav', $result['audio']['name']);
        $this->assertSame('passthrough', $result['vad']['detector']);
        $this->assertTrue($result['vad']['bypassed']);
        $this->assertSame([], $result['vad']['segments']);
    }
}
