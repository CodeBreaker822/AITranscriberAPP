<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioVadLog extends Model
{
    protected $fillable = [
        'user_id',
        'category_name',
        'source_type',
        'clip_index',
        'clip_start_ms',
        'clip_end_ms',
        'range_label',
        'duration_ms',
        'speech_detected',
        'speech_duration_ms',
        'segment_count',
        'speech_segments',
        'input_name',
        'input_size_bytes',
        'filtered_name',
        'filtered_size_bytes',
        'status',
        'message',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'clip_index' => 'integer',
        'clip_start_ms' => 'integer',
        'clip_end_ms' => 'integer',
        'duration_ms' => 'integer',
        'speech_detected' => 'boolean',
        'speech_duration_ms' => 'integer',
        'segment_count' => 'integer',
        'speech_segments' => 'array',
        'input_size_bytes' => 'integer',
        'filtered_size_bytes' => 'integer',
    ];
}
