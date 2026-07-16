<?php

namespace Tests\Unit;

use App\Services\Speakers\SpeakerDiarizationService;
use App\Services\Speakers\SpeakerDiarizationModelService;
use App\Services\Speakers\SpeakerTranscriptMerger;
use Tests\TestCase;

class SpeakerDiarizationServiceTest extends TestCase
{
    public function test_disabled_diarization_behaves_like_a_noop_block(): void
    {
        config(['services.speaker_diarization.driver' => 'disabled']);

        $models = $this->createMock(SpeakerDiarizationModelService::class);
        $models->expects($this->never())->method('activeModelPaths');

        $service = app()->makeWith(SpeakerDiarizationService::class, [
            'models' => $models,
        ]);
        $transcription = [
            'text' => 'Plain transcript.',
            'timestamps' => [['text' => 'Plain transcript.', 'start' => 0.0, 'end' => 1.0]],
        ];

        $this->assertFalse($service->canDiarize());
        $this->assertSame([], $service->diarizeSegments(__FILE__));
        $this->assertSame($transcription, $service->apply(__FILE__, $transcription));
    }

    public function test_it_maps_timestamp_overlap_and_formats_speaker_turns(): void
    {
        $audio = tempnam(sys_get_temp_dir(), 'diarization-test-');
        file_put_contents($audio, str_repeat("\0", 96_044));

        try {
            $result = $this->merge([
                'text' => 'Hello there. Yes.',
                'timestamps' => [
                    ['text' => 'Hello', 'start' => 0.0, 'end' => 0.8],
                    ['text' => 'there.', 'start' => 0.8, 'end' => 1.8],
                    ['text' => 'Yes.', 'start' => 2.0, 'end' => 2.8],
                ],
            ], [
                ['start' => 0.0, 'end' => 1.9, 'speaker_id' => 'speaker_1'],
                ['start' => 1.9, 'end' => 3.0, 'speaker_id' => 'speaker_2'],
            ], $audio);
        } finally {
            @unlink($audio);
        }

        $this->assertSame("Speaker 1: Hello there.\nSpeaker 2: Yes.", $result['text']);
        $this->assertSame('speaker_1', $result['timestamps'][0]['speaker_id']);
        $this->assertSame('speaker_1', $result['timestamps'][1]['speaker_id']);
        $this->assertSame('speaker_2', $result['timestamps'][2]['speaker_id']);
    }

    public function test_it_preserves_plain_text_when_only_one_speaker_is_found(): void
    {
        $audio = tempnam(sys_get_temp_dir(), 'diarization-test-');
        file_put_contents($audio, str_repeat("\0", 32_044));

        try {
            $result = $this->merge([
                'text' => 'Only one person speaking.',
                'timestamps' => [
                    ['text' => 'Only one person speaking.', 'start' => 0.0, 'end' => 1.0],
                ],
            ], [
                ['start' => 0.0, 'end' => 1.0, 'speaker_id' => 'speaker_1'],
            ], $audio);
        } finally {
            @unlink($audio);
        }

        $this->assertSame('Only one person speaking.', $result['text']);
        $this->assertSame('speaker_1', $result['timestamps'][0]['speaker_id']);
    }

    private function merge(array $transcription, array $segments, string $audio): array
    {
        return app(SpeakerTranscriptMerger::class)->merge($audio, $transcription, $segments, ['clip_start_ms' => 0]);
    }
}
