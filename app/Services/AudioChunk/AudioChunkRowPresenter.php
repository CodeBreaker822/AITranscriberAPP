<?php

namespace App\Services\AudioChunk;

use App\Models\AudioChunk;

class AudioChunkRowPresenter
{
    public function __construct(private readonly AudioChunkPayloadService $payloads) {}

    public function row(AudioChunk $row): array
    {
        return [
            'id' => $row->id,
            'clip_index' => (int) $row->clip_index,
            'clip_start_ms' => (int) $row->clip_start_ms,
            'clip_end_ms' => (int) $row->clip_end_ms,
            'range_label' => $row->range_label,
            'duration_ms' => (int) $row->duration_ms,
            'category_name' => $row->category_name ?: 'General',
            'source_type' => $this->payloads->sourceType($row->original_name),
            'status' => $row->status,
            'play_url' => route('audio-chunks.audio', ['audioChunk' => $row->id]),
            'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $row->id]),
            'translated_text' => $row->translated_text ?? null,
            'transcription_timestamps' => is_array($row->transcription_timestamps)
                ? $row->transcription_timestamps
                : [],
        ];
    }
}
