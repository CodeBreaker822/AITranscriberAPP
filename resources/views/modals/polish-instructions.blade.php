<dialog data-polish-dialog style="z-index: 2147483000;" class="fixed inset-0 m-auto hidden max-h-[calc(100dvh-2rem)] w-[min(92vw,36rem)] overflow-y-auto rounded-lg border border-white/10 bg-slate-950 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/80">
    <form method="dialog" class="p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Polish transcript</h2>
            </div>
            <button type="submit" value="cancel" class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-300 transition hover:bg-white/8 hover:text-white" aria-label="Close polish instructions">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 6 12 12" />
                    <path d="m18 6-12 12" />
                </svg>
            </button>
        </div>

        <label class="mt-5 block" for="polish-instructions">
            <span class="text-sm font-semibold text-slate-200">Select a preset or enter custom instructions on how to polish the transcript:</span>
            <span class="mt-3 grid gap-2 sm:grid-cols-3" aria-label="Instruction presets">
                <button type="button" data-polish-preset="translate-en" aria-pressed="false" class="min-h-9 cursor-pointer rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-cyan-300/30 hover:bg-cyan-300/10 hover:text-white">
                    Translate (EN)
                </button>
                <button type="button" data-polish-preset="fix-grammar" aria-pressed="false" class="min-h-9 cursor-pointer rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-cyan-300/30 hover:bg-cyan-300/10 hover:text-white">
                    Fix Grammar
                </button>
                <button type="button" data-polish-preset="translate-en-fix-grammar" aria-pressed="false" class="min-h-9 cursor-pointer rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-cyan-300/30 hover:bg-cyan-300/10 hover:text-white">
                    Translate (EN) / Fix Grammar
                </button>
            </span>
            <textarea
                id="polish-instructions"
                data-polish-instructions
                maxlength="2000"
                rows="6"
                class="mt-2 w-full resize-y rounded-lg border border-white/10 bg-slate-900 px-4 py-3 text-sm leading-6 text-white outline-none transition placeholder:text-slate-500 focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                placeholder="Example: Translate Cebuano, Bisaya, Filipino, and code-switched speech into polished English while preserving names, offices, acronyms, titles, numbers, and meaning."
            ></textarea>
        </label>
        <p data-polish-instructions-error class="mt-2 hidden text-sm text-rose-300">Enter instructions before polishing.</p>
        <p data-polish-replace-warning class="mt-3 rounded-lg border border-amber-300/20 bg-amber-300/10 px-3 py-2 text-sm leading-6 text-amber-100">
            Polishing again removes the current polished transcript and replaces it with the new result.
        </p>

        <div class="mt-5 flex justify-end gap-3">
            <button type="submit" value="cancel" class="min-h-9 cursor-pointer rounded-lg border border-white/10 bg-white/[0.03] px-4 py-2 text-sm font-semibold text-slate-200 transition hover:bg-white/8 hover:text-white">
                Cancel
            </button>
            <button type="button" data-polish-confirm class="min-h-9 cursor-pointer rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200">
                Polish transcript
            </button>
        </div>
    </form>
</dialog>
