<?php

namespace Tests\Unit;

use App\Services\AudioChunk\AudioChunkIngestionResult;
use Tests\TestCase;

class AudioChunkIngestionBoundaryTest extends TestCase
{
    public function test_ingestion_service_returns_domain_results_instead_of_http_responses(): void
    {
        $servicePaths = [
            base_path('app/Services/AudioChunk/AudioChunkIngestionService.php'),
            base_path('app/Services/AudioChunk/LiveAudioIngestion.php'),
            base_path('app/Services/AudioChunk/UploadedSectionIngestion.php'),
            base_path('app/Services/AudioChunk/UploadedBatchIngestion.php'),
        ];
        $service = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            $servicePaths,
        ));

        $this->assertIsString($service);
        $this->assertStringNotContainsString('Illuminate\Http\JsonResponse', $service);
        $this->assertStringNotContainsString('Illuminate\Http\Request', $service);
        $this->assertStringNotContainsString('response()->json', $service);
        $this->assertStringContainsString('storeLive(mixed $file, array $validated): AudioChunkIngestionResult', $service);
        $this->assertStringContainsString('storeUploadedSection(array $validated): AudioChunkIngestionResult', $service);
        $this->assertStringContainsString('storeUploadedBatch(array $validated): AudioChunkIngestionResult', $service);
    }

    public function test_ingestion_facade_delegates_to_focused_workflow_services(): void
    {
        $facade = file_get_contents(base_path('app/Services/AudioChunk/AudioChunkIngestionService.php'));

        $this->assertIsString($facade);
        $this->assertStringContainsString('private readonly LiveAudioIngestion $live', $facade);
        $this->assertStringContainsString('private readonly UploadedSectionIngestion $uploadedSection', $facade);
        $this->assertStringContainsString('private readonly UploadedBatchIngestion $uploadedBatch', $facade);
        $this->assertStringContainsString('return $this->live->store($file, $validated);', $facade);
        $this->assertStringContainsString('return $this->uploadedSection->store($validated);', $facade);
        $this->assertStringContainsString('return $this->uploadedBatch->store($validated);', $facade);
    }

    public function test_ingestion_result_payload_matches_existing_json_shape(): void
    {
        $result = AudioChunkIngestionResult::saved(['id' => 10]);

        $this->assertSame(AudioChunkIngestionResult::SAVED, $result->type);
        $this->assertSame([
            'message' => 'saved',
            'data' => ['id' => 10],
        ], $result->toResponsePayload());
    }
}
