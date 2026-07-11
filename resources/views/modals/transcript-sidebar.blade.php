<div data-app-sidebar="transcript" style="z-index: 2147483000;" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <button type="button" data-close-sidebar class="absolute inset-0 cursor-default bg-slate-950/75" aria-label="Close transcript panel"></button>

    <aside data-sidebar-panel class="absolute inset-y-0 right-0 flex w-[min(94vw,46rem)] translate-x-full flex-col border-l border-white/10 bg-slate-950 shadow-2xl transition-transform duration-300 ease-out" role="dialog" aria-modal="true" aria-label="Transcript">
        <header class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Transcript</p>
                @if ($activePage === 'live')
                    <p data-current-category class="mt-1 truncate text-sm text-slate-400">Choose project</p>
                @else
                    <p data-upload-transcript-category class="mt-1 truncate text-sm text-slate-400">Upload audio</p>
                @endif
            </div>
            <button type="button" data-close-sidebar class="grid h-9 w-9 shrink-0 cursor-pointer place-items-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-300 transition hover:bg-white/8 hover:text-white" aria-label="Close transcript panel">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 6 12 12" />
                    <path d="m18 6-12 12" />
                </svg>
            </button>
        </header>

        <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-white/10 px-4 py-3">
            @if ($activePage === 'live')
                <button type="button" data-furnish-live class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-100 transition hover:border-emerald-300/30 hover:bg-emerald-300/15">
                    Polish
                </button>
                <button type="button" data-summarize="live" class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-violet-300/20 bg-violet-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-violet-100 transition hover:border-violet-300/30 hover:bg-violet-300/15">
                    Summarize
                </button>
                <select data-export-live-mode class="min-h-9 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                    <option value="raw">Raw</option>
                    <option value="clean">Cleaned</option>
                </select>
                <select data-export-live-format class="min-h-9 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                    <option value="txt">TXT</option>
                    <option value="excel">Excel</option>
                    <option value="word">Microsoft Word</option>
                </select>
                <button type="button" data-export-live class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 3v12" />
                        <path d="m7 10 5 5 5-5" />
                        <path d="M5 21h14" />
                    </svg>
                    Export
                </button>
                <button type="button" data-log-live class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M8 6h13" />
                        <path d="M8 12h13" />
                        <path d="M8 18h13" />
                        <path d="M3 6h.01" />
                        <path d="M3 12h.01" />
                        <path d="M3 18h.01" />
                    </svg>
                    Log
                </button>
            @else
                <button type="button" data-furnish-upload class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-100 transition hover:border-emerald-300/30 hover:bg-emerald-300/15">
                    Polish
                </button>
                <button type="button" data-summarize="upload" class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-violet-300/20 bg-violet-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-violet-100 transition hover:border-violet-300/30 hover:bg-violet-300/15">
                    Summarize
                </button>
                <select data-export-upload-mode class="min-h-9 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                    <option value="raw">Raw</option>
                    <option value="clean">Cleaned</option>
                </select>
                <select data-export-upload-format class="min-h-9 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                    <option value="txt">TXT</option>
                    <option value="excel">Excel</option>
                    <option value="word">Microsoft Word</option>
                </select>
                <button type="button" data-export-upload class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 3v12" />
                        <path d="m7 10 5 5 5-5" />
                        <path d="M5 21h14" />
                    </svg>
                    Export
                </button>
                <button type="button" data-log-upload class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M8 6h13" />
                        <path d="M8 12h13" />
                        <path d="M8 18h13" />
                        <path d="M3 6h.01" />
                        <path d="M3 12h.01" />
                        <path d="M3 18h.01" />
                    </svg>
                    Log
                </button>
            @endif
        </div>

        @if ($activePage === 'live')
            <div data-stored-list class="min-h-0 flex-1 overflow-y-auto px-4 py-2">
                <div data-stored-empty class="w-full py-4">
                    <p class="text-sm text-slate-200">No entries yet.</p>
                </div>
            </div>
        @else
            <div data-upload-transcript-list class="min-h-0 flex-1 overflow-y-auto px-4 py-2">
                <div data-upload-transcript-empty class="w-full py-4">
                    <p class="text-sm text-slate-200">Transcript will appear here as each section finishes.</p>
                </div>
            </div>
        @endif
    </aside>
</div>
