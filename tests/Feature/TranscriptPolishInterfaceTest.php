<?php

namespace Tests\Feature;

use Tests\TestCase;

class TranscriptPolishInterfaceTest extends TestCase
{
    public function test_live_and_upload_pages_use_desktop_sidebars_and_separate_modal_scripts(): void
    {
        foreach (['/', '/upload'] as $path) {
            $response = $this->get($path);

            $response
                ->assertOk()
                ->assertDontSee('data-open-sidebar="transcript"', false)
                ->assertSee('data-open-sidebar="pending"', false)
                ->assertSee('bg-rose-500')
                ->assertDontSee('data-app-sidebar="transcript"', false)
                ->assertSee('data-app-sidebar="pending"', false)
                ->assertSee('js/modals/sidebar.js')
                ->assertSee('js/modals/polish-instructions.js')
                ->assertSee('js/modals/transcript-summary.js')
                ->assertSee('data-summary-dialog', false)
                ->assertSee('Raw transcript')
                ->assertSee('Cleaned transcript')
                ->assertSee('Select a preset or enter custom instructions on how to polish the transcript:')
                ->assertSee('Translate (EN)')
                ->assertSee('Fix Grammar')
                ->assertSee('Translate (EN) / Fix Grammar')
                ->assertSee('Polishing again removes the current polished transcript and replaces it with the new result.')
                ->assertSee('Project Name')
                ->assertSee('Language')
                ->assertSee('Multilingual')
                ->assertSee('AILogo.png')
                ->assertSee('Adaptive Speech Transcription and Recording Assistant. All rights reserved.')
                ->assertSee('fixed inset-0')
                ->assertSee('h-[100dvh] overflow-hidden')
                ->assertSee('min-h-0 flex-1 overflow-hidden')
                ->assertDontSee('data-upload-transcript-count', false)
                ->assertDontSee('data-stored-count', false)
                ->assertDontSee('Furnish Transcript');

            $html = $response->getContent();

            $this->assertSame(1, substr_count($html, 'data-open-sidebar="pending"'));
            $this->assertSame(0, substr_count($html, 'data-open-sidebar="transcript"'));
            $this->assertSame(0, substr_count($html, 'data-app-sidebar="transcript"'));
            $this->assertSame(1, substr_count($html, 'data-app-sidebar="pending"'));

            if ($path === '/') {
                $response->assertSee('data-live-transcript-badge', false);
                $response->assertSee('data-language-input', false);
                $this->assertSame(1, substr_count($html, 'data-stored-list'));
                $this->assertSame(1, substr_count($html, 'data-audio-queue'));
                $this->assertSame(1, substr_count($html, 'data-furnish-live'));
                $this->assertSame(1, substr_count($html, 'data-summarize="live"'));
                $this->assertSame(1, substr_count($html, 'data-live-cleaner-state'));
                $this->assertSame(1, substr_count($html, 'data-live-cleaner-progress-bar'));
                $this->assertSame(0, substr_count($html, 'data-upload-transcript-list'));
                continue;
            }

            $response->assertSee('data-upload-transcript-badge', false);
            $response
                ->assertSee('Audio to Text Converter')
                ->assertDontSee('Upload a long recording once, then process it in one-minute sections for steady progress and cleaner retries.');
            $response->assertSee('data-audio-chunk-seconds="60"', false);
            $response->assertSee('data-upload-language', false);
            $this->assertSame(1, substr_count($html, 'data-upload-transcript-list'));
            $this->assertSame(1, substr_count($html, 'data-upload-queue-list'));
            $this->assertSame(1, substr_count($html, 'data-furnish-upload'));
            $this->assertSame(1, substr_count($html, 'data-summarize="upload"'));
            $this->assertSame(1, substr_count($html, 'data-upload-duration'));
            $this->assertSame(0, substr_count($html, 'data-upload-sections'));
            $this->assertSame(1, substr_count($html, 'data-upload-status'));
            $this->assertSame(1, substr_count($html, 'data-upload-pause'));
            $this->assertSame(0, substr_count($html, 'data-stored-list'));
            $this->assertStringNotContainsString('parked', $html);
            $this->assertStringNotContainsString('Section processing', $html);
            $this->assertStringNotContainsString('Long file', $html);
            $this->assertStringContainsString('data-upload-file', $html);
            $this->assertStringContainsString('Browse file', $html);
        }

        $this->get('/upload')
            ->assertOk()
            ->assertSee('data-audio-chunk-seconds="60"', false);
    }
}
