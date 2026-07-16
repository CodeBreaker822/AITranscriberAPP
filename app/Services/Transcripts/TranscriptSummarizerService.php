<?php

namespace App\Services\Transcripts;

use App\Exceptions\TranscriptPolisherException;
use App\Services\HostedApi\HostedTranscriptionApiService;

class TranscriptSummarizerService
{
    private const INSTRUCTIONS = <<<'TEXT'
Create a concise, professional report from this transcript. Organize the report by topic rather than by transcript chunk or timestamp. Start with a short overall summary, then give each distinct topic a clear heading and summarize the important discussion beneath it. Under each topic, include decisions, action items, responsible people or offices, deadlines, dates, numbers, and unresolved issues when they are present. Use readable paragraphs and bullet lists where appropriate, omit empty sections, avoid repetition, and preserve the original meaning and factual details.
TEXT;

    public function __construct(
        private readonly HostedTranscriptionApiService $api,
    ) {
    }

    /** @return array{text: string, provider: string|null, model: string|null} */
    public function summarize(string $transcript): array
    {
        $result = $this->api->polish($transcript, [], [
            'instructions' => self::INSTRUCTIONS,
        ]);
        $text = (string) ($result['text'] ?? '');

        if (trim($text) === '') {
            throw new TranscriptPolisherException(
                'The transcription server returned a successful response without summary text.'
            );
        }

        return [
            // Preserve the server-selected AI's text exactly, including whitespace.
            'text' => $text,
            'provider' => $result['provider'] ?? null,
            'model' => $result['model'] ?? null,
        ];
    }
}
