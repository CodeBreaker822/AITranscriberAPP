<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class SpeakerDiarizationService
{
    public function __construct(
        private readonly SpeakerDiarizationModelService $models,
        private readonly AppSettingsService $settings,
    ) {}

    /**
     * Speaker separation is deliberately best-effort. A local model or worker
     * problem must never discard a successful hosted or Whisper transcript.
     */
    public function apply(string $audioPath, array $transcription, array $options = []): array
    {
        $segments = $this->diarizeSegments($audioPath, $options);

        if ($segments === []) {
            return $transcription;
        }

        return $this->mergeSegments($audioPath, $transcription, $segments, $options);
    }

    public function canDiarize(): bool
    {
        return $this->models->activeModelPaths() !== null;
    }

    /**
     * @return array<int, array{start: float|int, end: float|int, speaker_id: string}>
     */
    public function diarizeSegments(string $audioPath, array $options = []): array
    {
        $paths = $this->models->activeModelPaths();
        $sessionId = trim((string) ($options['speaker_session_id'] ?? ''));
        $releaseSession = (bool) ($options['release_session'] ?? $options['release_worker'] ?? false);

        if ($paths === null || ! is_file($audioPath)) {
            if ($releaseSession && $sessionId !== '') {
                $this->releaseSession($sessionId);
            }

            return [];
        }

        try {
            $payload = $this->workerRequest([
                'action' => 'diarize',
                'segmentation_model_path' => $paths['segmentation'],
                'embedding_model_path' => $paths['embedding'],
                'audio_path' => $audioPath,
                'threads' => max(1, (int) $this->settings->resourceProfile()['cpu_threads']),
                'threshold' => (float) config('services.speaker_diarization.cluster_threshold', 0.9),
                'match_threshold' => (float) config('services.speaker_diarization.match_threshold', 0.6),
                'max_speakers' => max(1, (int) config('services.speaker_diarization.max_speakers', 16)),
                'speaker_session_id' => $sessionId !== '' ? $sessionId : null,
                'release' => $releaseSession,
            ]);

            if ($payload === null) {
                if (($options['throw_on_failure'] ?? false) === true) {
                    throw new \RuntimeException('Speaker diarization worker is unavailable.');
                }

                return [];
            }

            if (is_string($payload['error'] ?? null) && trim($payload['error']) !== '') {
                throw new \RuntimeException(trim($payload['error']));
            }

            $segments = array_values(array_filter(
                is_array($payload['segments'] ?? null) ? $payload['segments'] : [],
                fn ($segment): bool => is_array($segment)
                    && is_numeric($segment['start'] ?? null)
                    && is_numeric($segment['end'] ?? null)
                    && is_string($segment['speaker_id'] ?? null),
            ));

            return $segments;
        } catch (Throwable $exception) {
            Log::warning('Local speaker diarization was skipped.', [
                'error' => $exception->getMessage(),
                'audio' => basename($audioPath),
            ]);

            if (($options['throw_on_failure'] ?? false) === true) {
                throw $exception;
            }

            return [];
        }
    }

    public function mergeSegments(string $audioPath, array $transcription, array $segments, array $options = []): array
    {
        $segments = array_values(array_filter(
            $segments,
            fn ($segment): bool => is_array($segment)
                && is_numeric($segment['start'] ?? null)
                && is_numeric($segment['end'] ?? null)
                && is_string($segment['speaker_id'] ?? null),
        ));

        if ($segments === []) {
            return $transcription;
        }

        return $this->merge($transcription, $segments, $audioPath, $options);
    }

    public function releaseWorker(): void
    {
        try {
            $this->workerRequest(['action' => 'release']);
        } catch (Throwable) {
            // The process also releases an idle model automatically.
        }
    }

    public function releaseSession(string $sessionId): void
    {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            return;
        }

        try {
            $this->workerRequest([
                'action' => 'release_session',
                'speaker_session_id' => $sessionId,
            ]);
        } catch (Throwable) {
            // Session profiles are also removed by the worker idle timeout.
        }
    }

    private function merge(array $transcription, array $segments, string $audioPath, array $options): array
    {
        $timestamps = array_values(array_filter(
            is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
            'is_array',
        ));

        if ($timestamps === []) {
            return $transcription;
        }

        $duration = $this->wavDurationSeconds($audioPath);
        $clipStart = max(0.0, ((int) ($options['clip_start_ms'] ?? 0)) / 1000);
        $timestampEnds = array_map(fn (array $item): float => (float) ($item['end'] ?? 0), $timestamps);
        $timestampStarts = array_filter(array_map(
            fn (array $item): float => (float) ($item['start'] ?? 0),
            $timestamps,
        ), fn (float $value): bool => $value > 0);
        $firstStart = $timestampStarts !== [] ? min($timestampStarts) : 0.0;
        $lastEnd = $timestampEnds !== [] ? max($timestampEnds) : 0.0;
        $absoluteTimeline = $clipStart > 1.0
            && ($firstStart >= ($clipStart - 1.0) || $lastEnd > ($duration + 2.0));

        if ($absoluteTimeline) {
            $segments = array_map(function (array $segment) use ($clipStart): array {
                $segment['start'] = (float) $segment['start'] + $clipStart;
                $segment['end'] = (float) $segment['end'] + $clipStart;

                return $segment;
            }, $segments);
        }

        foreach ($timestamps as &$timestamp) {
            $speaker = $this->speakerForTimestamp($timestamp, $segments);

            if ($speaker !== null) {
                $timestamp['speaker_id'] = $speaker;
            }
        }
        unset($timestamp);

        $speakers = array_values(array_unique(array_filter(array_map(
            fn (array $timestamp): ?string => is_string($timestamp['speaker_id'] ?? null)
                ? $timestamp['speaker_id']
                : null,
            $timestamps,
        ))));

        $transcription['timestamps'] = $timestamps;

        if (count($speakers) > 1) {
            $transcription['text'] = $this->speakerText($timestamps, (string) ($transcription['text'] ?? ''));
        }

        return $transcription;
    }

    private function speakerForTimestamp(array $timestamp, array $segments): ?string
    {
        $start = (float) ($timestamp['start'] ?? 0);
        $end = max($start, (float) ($timestamp['end'] ?? $start));
        $bestSpeaker = null;
        $bestOverlap = 0.0;

        foreach ($segments as $segment) {
            $segmentStart = (float) $segment['start'];
            $segmentEnd = (float) $segment['end'];
            $overlap = max(0.0, min($end, $segmentEnd) - max($start, $segmentStart));

            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $bestSpeaker = (string) $segment['speaker_id'];
            }
        }

        if ($bestSpeaker !== null) {
            return $bestSpeaker;
        }

        $midpoint = ($start + $end) / 2;
        $nearestDistance = INF;

        foreach ($segments as $segment) {
            $distance = $midpoint < (float) $segment['start']
                ? (float) $segment['start'] - $midpoint
                : max(0.0, $midpoint - (float) $segment['end']);

            if ($distance <= 0.75 && $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $bestSpeaker = (string) $segment['speaker_id'];
            }
        }

        return $bestSpeaker;
    }

    private function speakerText(array $timestamps, string $fallback): string
    {
        $groups = [];

        foreach ($timestamps as $timestamp) {
            $text = trim((string) ($timestamp['text'] ?? ''));
            $speaker = trim((string) ($timestamp['speaker_id'] ?? ''));

            if ($text === '' || $speaker === '') {
                continue;
            }

            $last = array_key_last($groups);

            if ($last === null || $groups[$last]['speaker'] !== $speaker) {
                $groups[] = ['speaker' => $speaker, 'text' => $text];
            } else {
                $groups[$last]['text'] = $this->appendToken($groups[$last]['text'], $text);
            }
        }

        if (count($groups) < 2) {
            return $fallback;
        }

        return implode("\n", array_map(
            fn (array $group): string => $this->speakerLabel($group['speaker']).': '.$group['text'],
            $groups,
        ));
    }

    private function appendToken(string $text, string $token): string
    {
        return preg_match('/^[.,!?;:%)\]}]/u', $token) === 1
            || preg_match('/[(\[{]$/u', $text) === 1
            ? $text.$token
            : $text.' '.$token;
    }

    private function speakerLabel(string $speaker): string
    {
        if (preg_match('/(\d+)$/', $speaker, $matches) === 1) {
            return 'Speaker '.max(1, (int) $matches[1]);
        }

        return 'Speaker';
    }

    private function wavDurationSeconds(string $audioPath): float
    {
        $size = @filesize($audioPath);

        return is_int($size) && $size > 44 ? max(0.0, ($size - 44) / 32_000) : 0.0;
    }

    /** @return array<string, mixed>|null */
    private function workerRequest(array $request): ?array
    {
        $endpointPath = storage_path('app/private/speaker-diarization-worker.json');

        if (! is_file($endpointPath)) {
            return null;
        }

        $endpoint = json_decode((string) @file_get_contents($endpointPath), true);
        $address = is_array($endpoint) ? trim((string) ($endpoint['address'] ?? '')) : '';
        $token = is_array($endpoint) ? trim((string) ($endpoint['token'] ?? '')) : '';

        if ($address === '' || $token === '') {
            return null;
        }

        $socket = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 2, STREAM_CLIENT_CONNECT);

        if (! is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, max(1, (int) config('services.speaker_diarization.timeout', 900)));
        $encoded = json_encode(['token' => $token, ...$request], JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || fwrite($socket, $encoded."\n") === false) {
            fclose($socket);
            throw new \RuntimeException('Speaker diarization worker request could not be sent.');
        }

        $response = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);

        if (($metadata['timed_out'] ?? false) === true) {
            throw new \RuntimeException('Local speaker diarization timed out.');
        }

        $payload = json_decode((string) $response, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Speaker diarization worker returned an invalid response.');
        }

        return $payload;
    }
}
