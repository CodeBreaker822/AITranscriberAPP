<?php

namespace App\Services\Audio;

class AudioSectionPlanner
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSections(int $durationMs, int $chunkSeconds): array
    {
        $chunkMs = max(1, $chunkSeconds) * 1000;
        $count = max(1, (int) ceil($durationMs / $chunkMs));

        return array_map(function (int $index) use ($chunkMs, $durationMs): array {
            $startMs = $index * $chunkMs;
            $endMs = min(($index + 1) * $chunkMs, $durationMs);

            return [
                'index' => $index + 1,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'duration_ms' => max(1, $endMs - $startMs),
                'range_label' => $this->formatRange($startMs, $endMs),
            ];
        }, range(0, $count - 1));
    }

    public function formatRange(int $startMs, int $endMs): string
    {
        return $this->formatClock($startMs).'-'.$this->formatClock($endMs);
    }

    public function formatClock(int $milliseconds): string
    {
        $totalSeconds = max(0, intdiv($milliseconds, 1000));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
