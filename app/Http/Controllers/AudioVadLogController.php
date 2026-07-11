<?php

namespace App\Http\Controllers;

use App\Models\AudioVadLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudioVadLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_name' => ['required', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'in:live,upload'],
        ]);

        $query = AudioVadLog::query()
            ->where('category_name', trim((string) $validated['category_name']));

        if (! empty($validated['source_type'])) {
            $query->where('source_type', $validated['source_type']);
        }

        $rows = $query
            ->orderBy('clip_start_ms')
            ->orderBy('clip_index')
            ->orderBy('id')
            ->get()
            ->map(function ($row): array {
                    $segments = $this->segments($row->speech_segments, (int) $row->clip_start_ms);

                return [
                    'id' => $row->id,
                    'user_id' => (int) $row->user_id,
                    'category_name' => $row->category_name,
                    'source_type' => $row->source_type,
                    'clip_index' => (int) $row->clip_index,
                    'clip_start_ms' => (int) $row->clip_start_ms,
                    'clip_end_ms' => (int) $row->clip_end_ms,
                    'range_label' => $row->range_label,
                    'duration_ms' => (int) $row->duration_ms,
                    'speech_detected' => (bool) $row->speech_detected,
                    'speech_duration_ms' => (int) $row->speech_duration_ms,
                    'segment_count' => (int) $row->segment_count,
                    'speech_segments' => $segments,
                    'input_name' => $row->input_name,
                    'input_size_bytes' => (int) $row->input_size_bytes,
                    'filtered_name' => $row->filtered_name,
                    'filtered_size_bytes' => (int) $row->filtered_size_bytes,
                    'status' => $row->status,
                    'message' => $row->message,
                    'created_at' => $row->created_at,
                ];
            });

        return response()->json([
            'data' => $rows,
            'count' => $rows->count(),
        ]);
    }

    private function segments(mixed $segments, int $clipStartMs): array
    {
        if (! $segments) {
            return [];
        }

        if (! is_array($segments)) {
            return [];
        }

        return array_values(array_filter(array_map(
            function ($segment) use ($clipStartMs): ?array {
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
                    'absolute_start_ms' => $clipStartMs + $startMs,
                    'absolute_end_ms' => $clipStartMs + $endMs,
                ];
            },
            $segments,
        )));
    }
}
