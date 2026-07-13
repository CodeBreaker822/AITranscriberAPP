<?php

namespace Tests\Feature;

use App\Http\Controllers\UploadedAudioTranscriptionController;
use App\Services\AudioFileChunkerService;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadedAudioTranscriptionControllerTest extends TestCase
{
    public function test_uploaded_audio_chunk_selection_uses_one_minute_sections(): void
    {
        config(['services.audio.chunk_seconds' => 60]);

        $request = Request::create('/audio-uploads', 'POST', [
            'local_path' => __FILE__,
            'chunk_seconds' => 60,
        ]);
        $chunker = $this->mock(AudioFileChunkerService::class, function ($mock): void {
            $mock->shouldReceive('createSessionFromPath')
                ->once()
                ->with(__FILE__)
                ->andReturn([
                    'session_id' => 'upload-session',
                    'directory' => dirname(__FILE__),
                    'source_path' => __FILE__,
                    'duration_ms' => 180000,
                ]);
            $mock->shouldReceive('createSession')->never();
            $mock->shouldReceive('buildSections')
                ->once()
                ->with(180000, 60)
                ->andReturn([
                    ['index' => 1, 'start_ms' => 0, 'end_ms' => 60000, 'duration_ms' => 60000, 'range_label' => '00:00-01:00'],
                    ['index' => 2, 'start_ms' => 60000, 'end_ms' => 120000, 'duration_ms' => 60000, 'range_label' => '01:00-02:00'],
                    ['index' => 3, 'start_ms' => 120000, 'end_ms' => 180000, 'duration_ms' => 60000, 'range_label' => '02:00-03:00'],
                ]);
        });

        $response = app(UploadedAudioTranscriptionController::class)->store($request, $chunker, app(AppSettingsService::class));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(3, $response->getData(true)['data']['count']);
    }

    public function test_uploaded_audio_chunk_selection_rejects_lengths_above_one_minute(): void
    {
        config(['services.audio.chunk_seconds' => 60]);

        $request = Request::create('/audio-uploads', 'POST', [
            'local_path' => __FILE__,
            'chunk_seconds' => 300,
        ]);
        $chunker = $this->mock(AudioFileChunkerService::class, function ($mock): void {
            $mock->shouldReceive('createSessionFromPath')->never();
            $mock->shouldReceive('createSession')->never();
        });

        try {
            app(UploadedAudioTranscriptionController::class)->store($request, $chunker, app(AppSettingsService::class));
            $this->fail('Expected chunk_seconds above one minute to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('chunk_seconds', $exception->errors());
        }
    }

    public function test_uploaded_audio_chunk_selection_follows_configured_chunk_seconds(): void
    {
        config(['services.audio.chunk_seconds' => 120]);

        $request = Request::create('/audio-uploads', 'POST', [
            'local_path' => __FILE__,
            'chunk_seconds' => 120,
        ]);
        $chunker = $this->mock(AudioFileChunkerService::class, function ($mock): void {
            $mock->shouldReceive('createSessionFromPath')
                ->once()
                ->with(__FILE__)
                ->andReturn([
                    'session_id' => 'upload-session',
                    'directory' => dirname(__FILE__),
                    'source_path' => __FILE__,
                    'duration_ms' => 240000,
                ]);
            $mock->shouldReceive('createSession')->never();
            $mock->shouldReceive('buildSections')
                ->once()
                ->with(240000, 120)
                ->andReturn([
                    ['index' => 1, 'start_ms' => 0, 'end_ms' => 120000, 'duration_ms' => 120000, 'range_label' => '00:00-02:00'],
                    ['index' => 2, 'start_ms' => 120000, 'end_ms' => 240000, 'duration_ms' => 120000, 'range_label' => '02:00-04:00'],
                ]);
        });

        $response = app(UploadedAudioTranscriptionController::class)->store($request, $chunker, app(AppSettingsService::class));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['data']['count']);
    }
}
