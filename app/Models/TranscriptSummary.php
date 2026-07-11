<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranscriptSummary extends Model
{
    protected $fillable = [
        'user_id',
        'category_name',
        'source_type',
        'summary_text',
        'provider',
        'model',
        'status',
        'error_message',
        'run_token',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
