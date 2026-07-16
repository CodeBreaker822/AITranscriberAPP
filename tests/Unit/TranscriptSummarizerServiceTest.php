<?php

namespace Tests\Unit;

use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Transcripts\TranscriptSummarizerService;
use App\Exceptions\TranscriptPolisherException;
use Mockery;
use PHPUnit\Framework\TestCase;

class TranscriptSummarizerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_uses_the_polisher_api_and_preserves_returned_text_exactly(): void
    {
        $returned = "# Decisions\n\n- Keep this bullet\n  - Keep indentation";
        $api = Mockery::mock(HostedTranscriptionApiService::class);
        $api->shouldReceive('polish')
            ->once()
            ->withArgs(fn (string $text, array $timestamps, array $options): bool => $text === 'Raw transcript'
                && $timestamps === []
                && str_contains($options['instructions'] ?? '', 'professional report')
                && str_contains($options['instructions'] ?? '', 'Organize the report by topic')
                && ! str_contains($options['instructions'] ?? '', 'response text field')
                && ! str_contains($options['instructions'] ?? '', 'JSON'))
            ->andReturn([
                'text' => $returned,
                'timestamps' => [],
                'provider' => 'gemini',
                'model' => 'same-polisher-model',
            ]);

        $result = (new TranscriptSummarizerService($api))->summarize('Raw transcript');

        $this->assertSame($returned, $result['text']);
        $this->assertSame('gemini', $result['provider']);
        $this->assertSame('same-polisher-model', $result['model']);
    }

    public function test_it_rejects_a_successful_but_empty_summary(): void
    {
        $api = Mockery::mock(HostedTranscriptionApiService::class);
        $api->shouldReceive('polish')->once()->andReturn([
            'text' => " \n ",
            'provider' => 'gemini',
            'model' => 'summary-model',
        ]);

        $this->expectException(TranscriptPolisherException::class);
        $this->expectExceptionMessage('successful response without summary text');

        (new TranscriptSummarizerService($api))->summarize('Raw transcript');
    }
}
