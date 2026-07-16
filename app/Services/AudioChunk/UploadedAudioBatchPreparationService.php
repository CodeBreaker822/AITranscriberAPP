<?php

namespace App\Services\AudioChunk;

use App\Services\Audio\UploadedAudioSectionPreparationService;

class UploadedAudioBatchPreparationService
{
    public function __construct(
        private readonly UploadedAudioSectionPreparationService $sections,
    ) {}

    public function prepare(array $validated): array
    {
        $sections = array_values($validated['sections']);
        $requestedConcurrency = (int) ($validated['concurrency'] ?? count($sections));
        $processConcurrencyLimit = max(1, (int) config('services.upload_prepare.process_concurrency', 2));
        $concurrency = max(1, min(
            $requestedConcurrency,
            count($sections),
            $processConcurrencyLimit,
        ));

        $prepared = array_map(
            fn (array $section): array => $this->sections->prepare([
                'upload_session_id' => $validated['upload_session_id'],
                'user_id' => (int) ($validated['user_id'] ?? 1),
                'category_name' => trim((string) $validated['category_name']),
                ...$section,
            ]),
            $sections,
        );

        return [
            'message' => 'prepared',
            'data' => $prepared,
            'concurrency' => $concurrency,
            'requested_concurrency' => $requestedConcurrency,
        ];
    }
}
