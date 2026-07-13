<?php

namespace Tests\Unit;

use App\Enums\TranscriptionEngine;
use PHPUnit\Framework\TestCase;

class TranscriptionEngineTest extends TestCase
{
    public function test_values_match_the_frontend_and_request_contract(): void
    {
        $this->assertSame(['online', 'offline'], TranscriptionEngine::values());
    }

    public function test_invalid_or_missing_option_defaults_to_online(): void
    {
        $this->assertSame(TranscriptionEngine::Online, TranscriptionEngine::fromOption(null));
        $this->assertSame(TranscriptionEngine::Online, TranscriptionEngine::fromOption('unexpected'));
    }
}
