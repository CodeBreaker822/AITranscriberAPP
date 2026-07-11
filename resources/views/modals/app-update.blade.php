<dialog data-app-update-dialog style="z-index: 2147483000;" class="fixed inset-0 z-[70] m-auto hidden w-[min(92vw,34rem)] rounded-lg border border-white/10 bg-slate-950 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/85">
    <div class="p-6 sm:p-7">
        <div class="flex items-center gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-cyan-300/15 text-cyan-200">
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 3v12" />
                    <path d="m7 10 5 5 5-5" />
                    <path d="M5 21h14" />
                </svg>
            </span>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Application update</p>
                <h2 class="mt-1 text-xl font-semibold text-white" data-app-update-title>Updating</h2>
            </div>
        </div>

        <p class="mt-5 text-sm leading-6 text-slate-300" data-app-update-message>
            A new version is available. Preparing the download…
        </p>
        <p class="mt-3 hidden rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3 text-sm leading-6 text-slate-300" data-app-update-notes></p>

        <div class="mt-5">
            <div class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">
                <span data-app-update-progress-label>Starting</span>
                <span data-app-update-progress-percent>0%</span>
            </div>
            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-white/10">
                <div data-app-update-progress-bar class="h-full w-0 rounded-full bg-cyan-300 transition-[width] duration-200"></div>
            </div>
        </div>

        <p class="mt-4 text-xs leading-5 text-slate-500">Keep AITranscriber open. It will close and restart automatically after the download.</p>

        <div class="mt-5 hidden justify-end" data-app-update-actions>
            <button type="button" data-app-update-retry class="min-h-10 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200">
                Retry update
            </button>
        </div>
    </div>
</dialog>
