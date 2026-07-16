<?php

namespace App\Services\Audio;

class UploadedAudioSectionPreparationService
{
    public function __construct(
        private readonly AudioFileChunkerService $chunker,
        private readonly SpeechAudioFilterService $speechFilter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function prepare(array $validated): array
    {
        $segment = $this->chunker->extractSegment(
            (string) $validated['upload_session_id'],
            (int) $validated['clip_index'],
            (int) $validated['clip_start_ms'],
            (int) $validated['duration_ms'],
        );
        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $speechAudio = $this->speechFilter->prepare($segment, $this->vadContext($validated, $userId, $categoryName));

        if (! $speechAudio['speech_detected']) {
            return $this->skippedResponseData($validated, [
                'prepared' => true,
                'source_name' => $segment['name'],
                'prepared_skipped' => true,
                'prepared_duration_ms' => (int) $segment['duration_ms'],
                'prepared_file_size_bytes' => (int) $segment['size'],
                'vad' => $speechAudio['vad'],
            ]);
        }

        $preparedAudio = $speechAudio['audio'];

        return [
            'prepared' => true,
            'speech_detected' => true,
            'source_name' => $segment['name'],
            'prepared_name' => $preparedAudio['name'],
            'prepared_duration_ms' => (int) $preparedAudio['duration_ms'],
            'prepared_file_size_bytes' => (int) $preparedAudio['size'],
            'vad' => $speechAudio['vad'],
            'source_type' => 'upload',
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function vadContext(array $validated, int $userId, string $categoryName): array
    {
        return [
            'user_id' => $userId,
            'category_name' => $categoryName,
            'source_type' => 'upload',
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function skippedResponseData(array $validated, array $extra = []): array
    {
        return array_merge([
            'skipped' => true,
            'reason' => 'no_speech_detected',
            'source_type' => 'upload',
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ], $extra);
    }

}
