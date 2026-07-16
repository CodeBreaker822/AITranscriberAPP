<?php

namespace App\Services\Audio;

use App\Services\Support\ServiceUserMessage;
use RuntimeException;
use Symfony\Component\Process\Process;

class SileroVadService implements SpeechActivityDetector
{
    /**
     * @return array{has_speech: bool, duration_ms: int, speech_ms: int, segments: array<int, array{start_ms: int, end_ms: int, start_seconds: float, end_seconds: float}>}
     */
    public function detect(string $audioPath): array
    {
        if (! is_file($audioPath)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $binary = $this->binaryPath();
        $process = new Process([
            $binary,
            '--audio',
            $audioPath,
            '--threshold',
            (string) config('services.silero_vad.threshold', 0.5),
            '--min-speech-ms',
            (string) config('services.silero_vad.min_speech_ms', 250),
            '--min-silence-ms',
            (string) config('services.silero_vad.min_silence_ms', 500),
            '--speech-pad-ms',
            (string) config('services.silero_vad.speech_pad_ms', 80),
        ]);
        $process->setTimeout((int) config('services.silero_vad.timeout', 30));
        $process->run();

        $payload = json_decode(trim($process->getOutput()), true);

        if (! $process->isSuccessful()) {
            $message = is_array($payload) && is_string($payload['error'] ?? null)
                ? $payload['error']
                : trim($process->getErrorOutput());

            throw new RuntimeException($message !== '' ? $message : 'Local speech detection failed.');
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Local speech detection returned an invalid response.');
        }

        $segments = array_values(array_filter(array_map(
            fn ($segment): ?array => $this->normalizeSegment($segment),
            is_array($payload['segments'] ?? null) ? $payload['segments'] : [],
        )));

        return [
            'has_speech' => (bool) ($payload['has_speech'] ?? ($segments !== [])),
            'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
            'speech_ms' => (int) ($payload['speech_ms'] ?? $this->speechDurationMs($segments)),
            'segments' => $segments,
            'detector' => 'silero',
        ];
    }

    private function normalizeSegment(mixed $segment): ?array
    {
        if (! is_array($segment)) {
            return null;
        }

        $startMs = max(0, (int) round((float) ($segment['start_ms'] ?? 0)));
        $endMs = max($startMs, (int) round((float) ($segment['end_ms'] ?? 0)));

        if ($endMs <= $startMs) {
            return null;
        }

        return [
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'start_seconds' => $startMs / 1000,
            'end_seconds' => $endMs / 1000,
        ];
    }

    private function speechDurationMs(array $segments): int
    {
        return array_sum(array_map(
            fn (array $segment): int => max(0, (int) $segment['end_ms'] - (int) $segment['start_ms']),
            $segments,
        ));
    }

    private function binaryPath(): string
    {
        $configured = trim((string) config('services.silero_vad.binary', ''));
        $binaryName = 'vad-cli.exe';
        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            base_path('vad/'.$binaryName),
            base_path('build/vad/'.$binaryName),
            base_path('vad-cli/target/release/'.$binaryName),
            base_path('vad-cli/target/debug/'.$binaryName),
        ]));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Local Silero VAD executable is missing. Build vad-cli before transcribing audio.');
    }
}
