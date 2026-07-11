<?php

namespace App\Services\AudioChunk;

class AudioChunkPayloadService
{
    public function sourceType(?string $originalName): string
    {
        return preg_match('/^chunk_\d+(?:-speech)?\.wav$/', (string) $originalName) === 1
            ? 'upload'
            : 'live';
    }

    public function vadContext(array $validated, int $userId, string $categoryName, string $sourceType): array
    {
        return [
            'user_id' => $userId,
            'category_name' => $categoryName,
            'source_type' => $sourceType,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ];
    }

    public function skippedResponseData(array $validated, string $sourceType, array $extra = []): array
    {
        return array_merge([
            'skipped' => true,
            'reason' => 'no_speech_detected',
            'source_type' => $sourceType,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ], $extra);
    }

    public function transcriptionForBatchClip(array $transcriptions, array $section, int $batchIndex): array
    {
        $clipIndex = (int) $section['clip_index'];

        foreach ($transcriptions as $transcription) {
            if (! is_array($transcription)) {
                continue;
            }

            if (isset($transcription['clip_index']) && (int) $transcription['clip_index'] === $clipIndex) {
                return [
                    'text' => (string) ($transcription['text'] ?? ''),
                    'timestamps' => is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
                ];
            }

            if (isset($transcription['queue_index']) && (int) $transcription['queue_index'] === $batchIndex) {
                return [
                    'text' => (string) ($transcription['text'] ?? ''),
                    'timestamps' => is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
                ];
            }
        }

        $fallback = $transcriptions[$batchIndex] ?? null;

        if (is_array($fallback)) {
            return [
                'text' => (string) ($fallback['text'] ?? ''),
                'timestamps' => is_array($fallback['timestamps'] ?? null) ? $fallback['timestamps'] : [],
            ];
        }

        return ['text' => '', 'timestamps' => []];
    }

    public function responseData(
        int $audioChunkId,
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        string $sourceType,
        bool $includePreparedMetadata = true,
    ): array {
        $data = [
            'id' => $audioChunkId,
            'user_id' => $userId,
            'category_name' => $categoryName,
            'source_type' => $sourceType,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'play_url' => route('audio-chunks.audio', ['audioChunk' => $audioChunkId]),
            'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $audioChunkId]),
            'translated_text' => $transcription['text'],
            'transcription_timestamps' => $transcription['timestamps'],
        ];

        if ($includePreparedMetadata) {
            $data['prepared_duration_ms'] = (int) $storedAudio['duration_ms'];
            $data['prepared_file_size_bytes'] = (int) $storedAudio['size'];
        }

        return $data;
    }

    public function isNoSpeechTranscript(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return in_array($normalized, ['', 'no speech detected', 'no speech detected.'], true);
    }
}
