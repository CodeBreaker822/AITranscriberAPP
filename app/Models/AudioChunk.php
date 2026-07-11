<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioChunk extends Model
{
    protected $fillable = [
        'user_id',
        'category_name',
        'clip_index',
        'clip_start_ms',
        'clip_end_ms',
        'range_label',
        'duration_ms',
        'mime_type',
        'original_name',
        'file_size_bytes',
        'audio_blob',
        'audio_path',
        'audio_size',
        'audio_hash',
        'translated_text',
        'transcription_timestamps',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'clip_index' => 'integer',
            'clip_start_ms' => 'integer',
            'clip_end_ms' => 'integer',
            'duration_ms' => 'integer',
            'file_size_bytes' => 'integer',
            'audio_size' => 'integer',
            'transcription_timestamps' => 'array',
        ];
    }
}
