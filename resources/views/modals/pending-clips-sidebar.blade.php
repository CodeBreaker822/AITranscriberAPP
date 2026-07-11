<div data-app-sidebar="pending" style="z-index: 2147483000;" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <button type="button" data-close-sidebar class="absolute inset-0 cursor-default bg-slate-950/75" aria-label="Close pending audio panel"></button>

    <aside data-sidebar-panel class="absolute inset-y-0 right-0 flex w-[min(94vw,34rem)] translate-x-full flex-col border-l border-white/10 bg-slate-950 shadow-2xl transition-transform duration-300 ease-out" role="dialog" aria-modal="true" aria-label="Pending audio">
        <header class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Pending audio</p>
                <h2 class="mt-1 text-lg font-semibold text-white">Pending clips</h2>
            </div>
            <button type="button" data-close-sidebar class="grid h-9 w-9 shrink-0 cursor-pointer place-items-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-300 transition hover:bg-white/8 hover:text-white" aria-label="Close pending audio panel">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 6 12 12" />
                    <path d="m18 6-12 12" />
                </svg>
            </button>
        </header>

        @if ($activePage === 'live')
            <div data-audio-queue class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div data-audio-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4">
                    <p class="text-sm text-slate-200">No pending recordings yet.</p>
                </div>
            </div>
        @else
            <div data-upload-queue-list class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div data-upload-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4">
                    <p class="text-sm text-slate-200">No pending recordings yet.</p>
                </div>
            </div>
        @endif
    </aside>
</div>
