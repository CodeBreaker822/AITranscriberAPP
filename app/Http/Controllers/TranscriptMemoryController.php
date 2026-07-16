<?php

namespace App\Http\Controllers;

use App\Services\Transcripts\TranscriptMemoryService;
use Illuminate\Http\RedirectResponse;

class TranscriptMemoryController extends Controller
{
    public function clear(TranscriptMemoryService $transcriptMemory): RedirectResponse
    {
        $removed = $transcriptMemory->purgeTranscriptText();

        return redirect()
            ->route('settings.edit')
            ->with(
                'status',
                "Transcript text cleared. Removed {$removed['formatted_size']} of transcript data.",
            );
    }
}
