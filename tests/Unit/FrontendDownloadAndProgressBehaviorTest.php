<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FrontendDownloadAndProgressBehaviorTest extends TestCase
{
    public function test_offline_model_catalog_requires_a_card_download_click(): void
    {
        $root = dirname(__DIR__, 2);
        $app = file_get_contents($root.'/resources/js/app.js');
        $models = file_get_contents($root.'/public/js/offline-model.js');
        $modal = file_get_contents($root.'/resources/views/modals/offline-model.blade.php');

        $this->assertStringContainsString('offline-model:catalog-request', $app);
        $this->assertStringContainsString('offline-model:catalog-request', $models);
        $this->assertStringNotContainsString('offline-model:download-request', $app.$models);
        $this->assertStringContainsString("optionButton.addEventListener('click', () => startDownload", $models);
        $this->assertStringContainsString('max-h-[calc(100vh-2rem)]', $modal);
        $this->assertStringContainsString('overflow-hidden', $modal);
    }

    public function test_upload_progress_resets_and_completes_for_each_section(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/resources/js/app.js');

        $this->assertStringContainsString('beginSectionProgress(index + 1, progressId', $script);
        $this->assertStringContainsString('await completeSectionProgress()', $script);
        $this->assertStringContainsString('Processing ${activeBatchPositions[progressIndex]} of ${total}', $script);
        $this->assertStringContainsString('activeSectionProgress = 100', $script);
        $this->assertStringNotContainsString('Math.round((complete / total) * 100)', $script);
    }
}
