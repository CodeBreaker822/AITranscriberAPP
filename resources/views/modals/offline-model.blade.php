<div data-offline-model-dialog aria-hidden="true" style="z-index: 2147483000;" class="pointer-events-none fixed inset-0 z-[60] hidden">
    <div data-offline-model-backdrop class="pointer-events-auto absolute inset-0 bg-slate-950/75 backdrop-blur-sm"></div>

    <section data-offline-model-expanded role="dialog" aria-modal="true" aria-labelledby="offline-model-title" class="pointer-events-auto absolute left-1/2 top-1/2 flex max-h-[calc(100vh-2rem)] w-[min(92vw,34rem)] -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-lg border border-white/10 bg-slate-950 p-4 text-slate-100 shadow-2xl sm:p-5">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-300/15 text-emerald-200">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 3v12" />
                        <path d="m7 10 5 5 5-5" />
                        <path d="M5 21h14" />
                    </svg>
                </span>
                <div>
                    <p class="text-[0.62rem] font-semibold uppercase tracking-[0.24em] text-emerald-300">Offline Whisper models</p>
                    <h2 id="offline-model-title" class="mt-0.5 text-base font-semibold text-white" data-offline-model-title>Installing model</h2>
                </div>
            </div>

            <button type="button" data-offline-model-minimize class="inline-flex min-h-9 shrink-0 items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-white/10 hover:text-white" aria-label="Minimize offline model download">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14" />
                </svg>
                Minimize
            </button>
        </div>

        <p class="mt-3 text-xs leading-5 text-slate-300" data-offline-model-message>
            Preparing the selected local model download.
        </p>

        <div class="mt-4 hidden" data-offline-model-progress>
            <div class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">
                <span data-offline-model-progress-label>Connecting</span>
                <span data-offline-model-progress-percent>0%</span>
            </div>
            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-white/10">
                <div data-offline-model-progress-bar class="h-full w-0 rounded-full bg-emerald-300 transition-[width] duration-200"></div>
            </div>
        </div>

        <p class="mt-3 hidden text-[0.68rem] leading-4 text-slate-500" data-offline-model-download-note>You may minimize this window and continue using AITranscriber while the offline model installs.</p>

        <div class="mt-3 hidden justify-end" data-offline-model-cancel-actions>
            <button type="button" data-offline-model-cancel class="min-h-10 cursor-pointer rounded-lg border border-rose-300/25 bg-rose-300/10 px-4 py-2 text-sm font-semibold text-rose-100 transition hover:border-rose-300/40 hover:bg-rose-300/15">
                Cancel download
            </button>
        </div>

        <div class="mt-3 hidden justify-end gap-2" data-offline-model-actions>
            <button type="button" data-offline-model-close class="min-h-10 rounded-lg border border-white/10 bg-white/[0.04] px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/10">
                Close
            </button>
            <button type="button" data-offline-model-retry class="min-h-10 rounded-lg bg-emerald-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-emerald-200">
                Retry download
            </button>
        </div>
    </section>

    <button type="button" data-offline-model-compact class="pointer-events-auto absolute bottom-4 right-4 hidden w-[min(90vw,22rem)] items-center gap-3 rounded-lg border border-emerald-300/25 bg-slate-950/95 p-3 text-left text-slate-100 shadow-2xl transition hover:border-emerald-300/40" aria-label="Restore offline model download progress">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-300/15 text-emerald-200">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 3v12" />
                <path d="m7 10 5 5 5-5" />
                <path d="M5 21h14" />
            </svg>
        </span>
        <span class="min-w-0 flex-1">
            <span class="flex items-center justify-between gap-3 text-xs font-semibold">
                <span data-offline-model-compact-label>Installing offline model</span>
                <span data-offline-model-progress-percent>0%</span>
            </span>
            <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-white/10">
                <span data-offline-model-progress-bar class="block h-full w-0 rounded-full bg-emerald-300 transition-[width] duration-200"></span>
            </span>
            <span class="mt-1.5 block text-[0.65rem] uppercase tracking-[0.16em] text-slate-400">Click to restore</span>
        </span>
    </button>
</div>
