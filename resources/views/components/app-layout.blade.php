@props([
    'title' => 'AI Transcriber',
    'activePage' => 'live',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-ui-page="{{ $activePage }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#081018">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title }}</title>

        @fonts
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="{{ asset('notification.js') }}"></script>
        <script src="{{ asset('loader.js') }}"></script>
        <script src="{{ asset('js/modals/sidebar.js') }}"></script>
        <script src="{{ asset('js/modals/polish-instructions.js') }}"></script>
        <script src="{{ asset('js/modals/transcript-summary.js') }}" defer></script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        data-page="{{ $activePage }}"
        data-desktop-dev="{{ config('app.desktop_dev') ? 'true' : 'false' }}"
        data-speech-provider="{{ $speechProvider }}"
        data-update-connectivity-url="{{ route('app-update.connectivity') }}"
        data-update-status-url="{{ route('app-update.status') }}"
        data-update-download-url="{{ route('app-update.download') }}"
        data-offline-model-status-url="{{ route('offline-model.status') }}"
        data-offline-model-download-url="{{ route('offline-model.download') }}"
        data-speaker-session-release-url="{{ route('speaker-sessions.release') }}"
        data-summary-status-url="{{ route('transcripts.summary.show') }}"
        data-summary-store-url="{{ route('transcripts.summary.store') }}"
        data-resource-cpu-threads="{{ $resourceProfile['cpu_threads'] }}"
        data-resource-memory-budget-mb="{{ $resourceProfile['memory_budget_mb'] }}"
        data-resource-gpu-available="{{ $resourceProfile['gpu_available'] ? 'true' : 'false' }}"
        data-resource-gpu-vram-budget-mb="{{ $resourceProfile['gpu_vram_budget_mb'] }}"
        data-audio-chunk-seconds="{{ $audioChunkSeconds }}"
        @if ($activePage === 'live')
            data-upload-url="{{ route('audio-chunks.store') }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-vad-log-url="{{ route('audio-vad-logs.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-default-user-id="1"
            data-default-category-name=""
            data-play-url-base="{{ url('/audio-chunks') }}"
            data-delete-url-base="{{ url('/audio-chunks') }}"
        @elseif ($activePage === 'upload')
            data-upload-audio-url="{{ route('audio-uploads.store') }}"
            data-upload-audio-prepare-url="{{ route('audio-uploads.sections.prepare') }}"
            data-upload-audio-prepare-batch-url="{{ route('audio-uploads.sections.prepare-batch') }}"
            data-upload-audio-diarize-url="{{ route('audio-uploads.sections.diarize') }}"
            data-upload-session-status-url="{{ route('audio-uploads.sessions.status') }}"
            data-audio-chunk-url="{{ route('audio-chunks.store') }}"
            data-audio-chunk-batch-url="{{ route('audio-chunks.store-batch') }}"
            data-transcribe-max-batch-duration-ms="{{ $transcribeMaxBatchDurationMs }}"
            data-transcribe-max-batch-clips="{{ $transcribeMaxBatchClips }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-vad-log-url="{{ route('audio-vad-logs.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-default-user-id="1"
        @endif
        class="h-[100dvh] overflow-hidden bg-[linear-gradient(180deg,_#071018_0%,_#0d1620_42%,_#101820_100%)] font-sans text-slate-100 selection:bg-cyan-300/20 selection:text-white"
    >
        <div data-app-shell class="h-full p-3 sm:p-4">
            <div data-app-frame class="mx-auto flex h-full min-h-0 w-full max-w-7xl flex-col gap-3">
                <x-app-header
                    :active-page="$activePage"
                    :has-offline-transcription-model="$hasOfflineTranscriptionModel"
                />

                <main data-app-main class="min-h-0 flex-1 {{ in_array($activePage, ['live', 'upload'], true) ? 'overflow-hidden' : 'overflow-y-auto' }}">
                    {{ $slot }}
                </main>

                <x-app-footer />
            </div>
        </div>

        @if (in_array($activePage, ['live', 'upload'], true))
            @include('modals.polish-instructions')
            @include('modals.transcript-summary')
            @include('modals.pending-clips-sidebar', ['activePage' => $activePage])
        @endif
    </body>
</html>
