<?php

namespace App\Services\Speakers;

class SpeakerTranscriptMerger
{
    public function merge(string $audioPath, array $transcription, array $segments, array $options = []): array
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
}
