<?php

namespace App\Services\Audio;

use App\Models\AudioVadLog;
use App\Services\Support\ServiceUserMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SpeechAudioFilterService
{
    public function __construct(
        private readonly SpeechActivityDetectorResolver $detectors,
        private readonly AudioProcessRunner $processes,
        private readonly AudioDurationProbe $durations,
    ) {
    }

    /**
     * @param  array{path: string, name: string, mime_type: string, size: int, duration_ms: int}  $audio
     * @return array{speech_detected: bool, audio: array, vad: array}
     */
    public function prepare(array $audio, array $context): array
    {
        $vad = $this->detectors
            ->detector($context['vad_driver'] ?? null)
            ->detect((string) $audio['path']);

        if (($vad['bypassed'] ?? false) === true) {
            return [
                'speech_detected' => true,
                'audio' => $audio,
                'vad' => $vad,
            ];
        }

        if (! $vad['has_speech'] || $vad['segments'] === []) {
            $this->recordVadResult($audio, null, $context, $vad, false);

            return [
                'speech_detected' => false,
                'audio' => $audio,
                'vad' => $vad,
            ];
        }

        $filtered = $this->mergeSpeechSegments($audio, $vad['segments']);
        $this->recordVadResult($audio, $filtered, $context, $vad, true);

        return [
            'speech_detected' => true,
            'audio' => $filtered,
            'vad' => $vad,
        ];
    }

    private function mergeSpeechSegments(array $audio, array $segments): array
    {
        $inputPath = (string) $audio['path'];
        $outputPath = dirname($inputPath).DIRECTORY_SEPARATOR.pathinfo((string) $audio['name'], PATHINFO_FILENAME).'-speech.wav';
        $filterParts = [];
        $concatInputs = [];

        foreach (array_values($segments) as $index => $segment) {
            $label = 'a'.$index;
            $start = number_format(((int) $segment['start_ms']) / 1000, 3, '.', '');
            $end = number_format(((int) $segment['end_ms']) / 1000, 3, '.', '');
            $filterParts[] = "[0:a]atrim=start={$start}:end={$end},asetpts=PTS-STARTPTS[{$label}]";
            $concatInputs[] = "[{$label}]";
        }

        $outputLabel = count($segments) === 1 ? $concatInputs[0] : '[out]';

        if (count($segments) > 1) {
            $filterParts[] = implode('', $concatInputs).'concat=n='.count($segments).':v=0:a=1[out]';
        }

        $this->processes->run([
            $this->processes->ffmpegPath(),
            '-y',
            '-i',
            $inputPath,
            '-filter_complex',
            implode(';', $filterParts),
            '-map',
            $outputLabel,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ], ServiceUserMessage::audioPrepareFailed());

        if (! is_file($outputPath)) {
            throw new RuntimeException(ServiceUserMessage::audioPrepareFailed());
        }

        return [
            'path' => $outputPath,
            'name' => basename($outputPath),
            'mime_type' => 'audio/wav',
            'size' => filesize($outputPath) ?: 0,
            'duration_ms' => $this->durations->milliseconds($outputPath),
        ];
    }

    private function recordVadResult(
        array $input,
        ?array $filtered,
        array $context,
        array $vad,
        bool $speechDetected,
    ): void {
        if (! Schema::hasTable('audio_vad_logs')) {
            return;
        }

        try {
            AudioVadLog::query()->create([
                'user_id' => (int) ($context['user_id'] ?? 1),
                'category_name' => $context['category_name'] ?? null,
                'source_type' => (string) ($context['source_type'] ?? 'unknown'),
                'clip_index' => (int) ($context['clip_index'] ?? 0),
                'clip_start_ms' => (int) ($context['clip_start_ms'] ?? 0),
                'clip_end_ms' => (int) ($context['clip_end_ms'] ?? 0),
                'range_label' => (string) ($context['range_label'] ?? ''),
                'duration_ms' => (int) ($context['duration_ms'] ?? ($input['duration_ms'] ?? 0)),
                'speech_detected' => $speechDetected,
                'speech_duration_ms' => (int) ($vad['speech_ms'] ?? 0),
                'segment_count' => count($vad['segments'] ?? []),
                'speech_segments' => $vad['segments'] ?? [],
                'input_name' => $input['name'] ?? null,
                'input_size_bytes' => (int) ($input['size'] ?? 0),
                'filtered_name' => $filtered['name'] ?? null,
                'filtered_size_bytes' => (int) ($filtered['size'] ?? 0),
                'status' => $speechDetected ? 'speech_detected' : 'no_speech',
                'message' => $speechDetected ? null : 'Local VAD detected no speech; hosted transcription was skipped.',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Audio VAD log could not be recorded.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) ($context['clip_index'] ?? 0),
            ]);
        }
    }

}
