<?php

namespace Tests\Unit;

use App\Enums\TranscriptionEngine;
use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Speech\OfflineWhisperService;
use App\Services\Speech\SpeechToTextService;
use Mockery;
use PHPUnit\Framework\TestCase;

class SpeechToTextServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_dispatches_offline_transcription_to_whisper(): void
    {
        $hosted = Mockery::mock(HostedTranscriptionApiService::class);
        $hosted->shouldNotReceive('transcribe');
        $offline = Mockery::mock(OfflineWhisperService::class);
        $offline->shouldReceive('transcribe')
            ->once()
            ->with('prepared.wav', ['engine' => TranscriptionEngine::Offline->value, 'language_code' => 'en'])
            ->andReturn([
                'text' => 'Offline result.',
                'timestamps' => [],
                'provider' => 'whisper.cpp',
                'model' => 'large-v3-turbo-q8_0',
            ]);

        $result = (new SpeechToTextService($hosted, $offline))->transcribe('prepared.wav', [
            'engine' => TranscriptionEngine::Offline->value,
            'language_code' => 'en',
        ]);

        $this->assertSame('Offline result.', $result['text']);
    }

    public function test_online_remains_the_default_engine(): void
    {
        $hosted = Mockery::mock(HostedTranscriptionApiService::class);
        $hosted->shouldReceive('transcribe')
            ->once()
            ->with('prepared.wav', ['language_code' => 'en'])
            ->andReturn(['text' => 'Hosted result.', 'timestamps' => []]);
        $offline = Mockery::mock(OfflineWhisperService::class);
        $offline->shouldNotReceive('transcribe');

        $result = (new SpeechToTextService($hosted, $offline))->transcribe('prepared.wav', [
            'language_code' => 'en',
        ]);

        $this->assertSame('Hosted result.', $result['text']);
    }
}
