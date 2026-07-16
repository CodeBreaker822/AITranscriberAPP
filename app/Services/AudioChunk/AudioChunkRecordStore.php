<?php

namespace App\Services\AudioChunk;

use App\Models\AudioChunk;
use App\Services\Audio\StoredAudioService;

class AudioChunkRecordStore
{
    public function __construct(private readonly StoredAudioService $storedAudio) {}

    public function create(
        array $validated,
        array $storedAudio,
        int $userId,
        string $categoryName,
        ?string $translatedText,
        array $timestamps,
        string $status,
    ): AudioChunk {
        return AudioChunk::query()->create([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => (string) $storedAudio['mime_type'],
            'original_name' => (string) $storedAudio['name'],
            'file_size_bytes' => (int) $storedAudio['size'],
            'audio_blob' => '',
            'translated_text' => $translatedText,
            'transcription_timestamps' => $timestamps,
            'status' => $status,
        ]);
    }

    public function attachStoredAudio(int $audioChunkId, string $wavPath, string $sessionId): void
    {
        try {
            $metadata = $this->storedAudio->persistWav($wavPath, $sessionId, $audioChunkId);
            AudioChunk::query()->whereKey($audioChunkId)->update([
                ...$metadata,
                'file_size_bytes' => $metadata['audio_size'],
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            AudioChunk::query()->whereKey($audioChunkId)->delete();

            throw $exception;
        }
    }

    public function delete(int $audioChunk): bool
    {
        $row = AudioChunk::query()->find($audioChunk);

        if (! $row) {
            return false;
        }

        $row->delete();
        $this->storedAudio->delete($row->audio_path ?? null);

        return true;
    }
}
