@props([
    'activePage' => 'live',
    'hasOfflineTranscriptionModel' => false,
])

<header data-app-header class="shrink-0 rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <a href="{{ route('transcription.live') }}" class="flex min-w-0 items-center gap-3">
            <span class="flex shrink-0 items-center gap-2">
                <img
                    src="{{ asset('DILG-Logo.png') }}"
                    alt="Department of the Interior and Local Government"
                    class="h-11 w-11 object-contain sm:h-12 sm:w-12"
                >
                <img
                    src="{{ asset('AgSUR-Brand-Logo.png') }}"
                    alt="Department of the Interior and Local Government"
                    class="h-11 w-11 object-contain sm:h-12 sm:w-12"
                >
                <img
                    src="{{ asset('AILogo.png') }}"
                    alt="AI Transcriber"
                    class="h-11 w-11 rounded-lg object-contain sm:h-12 sm:w-12"
                >
            </span>

            <span class="min-w-0">
                <span class="block text-xl font-semibold tracking-tight text-white sm:text-2xl">ASTRA</span>
                <span data-brand-description class="mt-0.5 hidden text-xs text-slate-400 sm:block">Agusan del Sur Transcription and Recording Assistant
                Empower Smarter Governance Through Digital Documentation.</span>
            </span>
        </a>

        <div class="flex w-full items-center gap-2 lg:w-auto">
            <nav aria-label="Transcription tools" class="flex min-w-0 flex-1 rounded-lg border border-white/10 bg-white/[0.03] p-1 lg:w-auto lg:flex-none">
                @foreach ($navItems as $item)
                    <a
                        href="{{ $item['href'] }}"
                        aria-current="{{ $activePage === $item['key'] ? 'page' : 'false' }}"
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition lg:flex-none {{ $activePage === $item['key'] ? 'bg-cyan-300 text-slate-950 shadow-[0_10px_30px_rgba(103,232,249,0.16)]' : 'text-slate-300 hover:bg-white/8 hover:text-white' }}"
                    >
                        @if ($item['icon'] === 'mic')
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 18a4 4 0 0 0 4-4V8a4 4 0 1 0-8 0v6a4 4 0 0 0 4 4Z" />
                                <path d="M5 11v1a7 7 0 0 0 14 0v-1" />
                                <path d="M12 18v4" />
                                <path d="M8 22h8" />
                            </svg>
                        @else
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 3v12" />
                                <path d="m7 8 5-5 5 5" />
                                <path d="M5 21h14" />
                                <path d="M5 17h14" />
                            </svg>
                        @endif
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <button
                type="button"
                data-offline-model-download
                title="Download a local Whisper transcription model"
                @if ($hasOfflineTranscriptionModel) hidden @endif
                class="{{ $hasOfflineTranscriptionModel ? 'hidden' : 'inline-flex' }} h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 text-sm font-medium text-emerald-100 transition hover:border-emerald-300/35 hover:bg-emerald-300/15 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 3v12" />
                    <path d="m7 10 5 5 5-5" />
                    <path d="M5 21h14" />
                </svg>
                <span data-offline-model-label>Download Offline</span>
            </button>

            <div
                data-transcription-engine-switch
                @if (! $hasOfflineTranscriptionModel) hidden @endif
                class="{{ $hasOfflineTranscriptionModel ? 'flex' : 'hidden' }} h-11 shrink-0 items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3"
                title="Choose online or offline transcription"
            >
                <span class="text-xs font-semibold text-cyan-200">Online</span>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" class="peer sr-only" data-transcription-engine-toggle aria-label="Use offline transcription">
                    <span class="h-6 w-11 rounded-full bg-slate-700 transition peer-checked:bg-emerald-400/80 peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-300/60"></span>
                    <span class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                </label>
                <span class="text-xs font-semibold text-emerald-200">Offline</span>
            </div>

            <a
                href="{{ route('settings.edit') }}"
                aria-label="Settings"
                title="Settings"
                aria-current="{{ $activePage === 'settings' ? 'page' : 'false' }}"
                class="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-white/10 transition {{ $activePage === 'settings' ? 'bg-cyan-300 text-slate-950 shadow-[0_10px_30px_rgba(103,232,249,0.16)]' : 'bg-white/[0.03] text-slate-300 hover:bg-white/8 hover:text-white' }}"
            >
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" />
                    <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6V20a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1H4a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6V4a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.22.31.42.64.6 1H20a2 2 0 1 1 0 4h-.09c-.18.36-.38.69-.6 1Z" />
                </svg>
            </a>
        </div>
    </div>
</header>

@include('modals.app-update')
@include('modals.offline-model')
<script src="{{ asset('js/modals/app-update.js') }}" defer></script>
<script src="{{ asset('js/offline-model.js') }}" defer></script>
