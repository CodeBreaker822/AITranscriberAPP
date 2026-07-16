<?php

namespace App\Services\AudioChunk;

use App\Models\AudioChunk;
use App\Services\Audio\StoredAudioService;

class AudioChunkAudioPayloadService
{
    public function __construct(private readonly StoredAudioService $storedAudio) {}

    public function payload(int $audioChunk): ?array
    {
        $row = AudioChunk::query()->find($audioChunk);

        if (! $row) {
            return null;
        }

        $audioPath = $this->storedAudio->absolutePath($row->audio_path ?? null);

        if ($audioPath !== null) {
            return [
                'type' => 'file',
                'path' => $audioPath,
                'mime_type' => $row->mime_type ?: 'audio/flac',
                'size' => filesize($audioPath) ?: 0,
            ];
        }

        $audioBlob = is_string($row->audio_blob ?? null) ? $row->audio_blob : '';

        if ($audioBlob === '') {
            return null;
        }

        return [
            'type' => 'blob',
            'contents' => $audioBlob,
            'mime_type' => $row->mime_type ?: 'audio/webm',
            'size' => strlen($audioBlob),
        ];
    }
}
