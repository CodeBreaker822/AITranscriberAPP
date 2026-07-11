<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleanTranscriptChunk extends Model
{
    protected $fillable = [
        'audio_chunk_id',
        'user_id',
        'category_name',
        'clip_index',
        'clip_start_ms',
        'clip_end_ms',
        'range_label',
        'raw_text',
        'clean_text',
        'clean_timestamps',
        'provider',
        'model',
        'instruction_hash',
        'status',
    ];

    protected $casts = [
        'audio_chunk_id' => 'integer',
        'user_id' => 'integer',
        'clip_index' => 'integer',
        'clip_start_ms' => 'integer',
        'clip_end_ms' => 'integer',
        'clean_timestamps' => 'array',
    ];
}
