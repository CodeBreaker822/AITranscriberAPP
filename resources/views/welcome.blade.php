<x-app-layout title="Live Transcription | AI Transcriber" active-page="live">
    <div data-workspace class="h-full min-h-0 overflow-hidden">
        <div data-workspace-grid class="grid h-full min-h-0 grid-rows-[1fr_auto] gap-3 lg:grid-cols-[1.35fr_0.65fr] lg:grid-rows-1">
            <section data-transcript-panel class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-white/10 bg-slate-950/70 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                <header class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Transcript</p>
                            <span data-live-transcript-badge class="grid h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 text-[0.65rem] font-bold leading-none text-white">0</span>
                        </div>
                        <p data-current-category class="mt-1 truncate text-sm text-slate-400">Choose project</p>
                    </div>
                </header>

                <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-white/10 px-3 py-2">
                    <button type="button" data-furnish-live class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-emerald-100 transition hover:border-emerald-300/30 hover:bg-emerald-300/15">
                        Polish
                    </button>
                    <button type="button" data-summarize="live" class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-violet-300/20 bg-violet-300/10 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-violet-100 transition hover:border-violet-300/30 hover:bg-violet-300/15">
                        Summarize
                    </button>
                    <select data-export-live-mode class="min-h-8 rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                        <option value="raw">Raw</option>
                        <option value="clean">Cleaned</option>
                    </select>
                    <select data-export-live-format class="min-h-8 rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                        <option value="txt">TXT</option>
                        <option value="excel">Excel</option>
                        <option value="word">Microsoft Word</option>
                    </select>
                    <button type="button" data-export-live class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 3v12" />
                            <path d="m7 10 5 5 5-5" />
                            <path d="M5 21h14" />
                        </svg>
                        Export
                    </button>
                    <button type="button" data-log-live class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M8 6h13" />
                            <path d="M8 12h13" />
                            <path d="M8 18h13" />
                            <path d="M3 6h.01" />
                            <path d="M3 12h.01" />
                            <path d="M3 18h.01" />
                        </svg>
                        Log
                    </button>
                </div>

                <div data-stored-list class="min-h-0 flex-1 overflow-y-auto px-4 py-2">
                    <div data-stored-empty class="w-full py-4">
                        <p class="text-sm text-slate-200">No entries yet.</p>
                    </div>
                </div>
            </section>

            <aside data-workspace-sidebar class="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 overflow-visible">
                <section class="relative z-50 flex min-h-0 flex-col overflow-visible rounded-lg border border-white/10 bg-slate-950/70 p-2.5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                    <div class="grid grid-cols-[minmax(0,1fr)_11rem] gap-2">
                        <div class="relative z-50">
                            <p class="mb-1 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Project Name</p>
                            <input
                                type="text"
                                data-category-input
                                placeholder="Project name"
                                autocomplete="off"
                                class="w-full rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1.5 text-[0.72rem] text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                            >
                            <div
                                data-category-suggestions
                                class="absolute left-0 right-0 top-full z-50 mt-2 hidden max-h-48 overflow-y-auto rounded-lg border border-cyan-300/20 bg-slate-950 p-2 shadow-[0_20px_60px_rgba(0,0,0,0.55)]"
                            ></div>
                        </div>

                        <label class="block" data-language-control>
                            <span class="mb-1 block text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Language</span>
                            <select data-language-input class="w-full rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1.5 text-[0.72rem] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                                @foreach ($languageOptions as $option)
                                    <option value="{{ $option['value'] }}" @selected($loop->first)>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="hidden" data-whisper-model-control>
                            <span class="mb-1 block text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Whisper model</span>
                            <select class="w-full rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1.5 text-[0.72rem] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20" data-whisper-model>
                                @foreach ($whisperModels as $model)
                                    <option value="{{ $model['id'] }}" @selected($model['id'] === 'turbo')>{{ $model['label'] }} · {{ $model['size'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <button
                        type="button"
                        data-record-toggle
                        data-recording="false"
                        aria-pressed="false"
                        class="group relative z-0 mt-2 flex w-full cursor-pointer items-center justify-between gap-3 rounded-lg border border-cyan-300/20 bg-cyan-300/10 px-2.5 py-2 text-left outline-none transition hover:border-cyan-300/35 hover:bg-cyan-300/15 focus-visible:ring-2 focus-visible:ring-cyan-300/60 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span class="flex min-w-0 items-center gap-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-white/10 bg-slate-950/70 text-emerald-300 transition group-data-[recording=true]:text-rose-300">
                                <span data-record-icon="play">
                                    <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                        <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                    </svg>
                                </span>
                                <span data-record-icon="stop" class="hidden">
                                    <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                        <rect x="6.5" y="6.5" width="11" height="11" rx="2" />
                                    </svg>
                                </span>
                            </span>
                            <span class="min-w-0">
                                <span data-record-state class="block text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-cyan-300">Listening</span>
                                <span data-record-caption class="mt-0.5 block truncate text-sm font-semibold text-white">Ready to capture</span>
                            </span>
                        </span>
                        <span class="text-[0.65rem] uppercase tracking-[0.18em] text-slate-400">Live</span>
                    </button>
                </section>

                <section class="relative z-0 shrink-0 rounded-lg border border-white/10 bg-slate-950/70 p-2.5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                    <div class="flex items-center justify-end">
                        <button type="button" data-open-sidebar="pending" aria-expanded="false" class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold text-slate-100 transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                            Pending <span data-audio-count class="text-cyan-300">0</span>
                        </button>
                    </div>

                    <div class="mt-2 rounded-lg border border-white/10 bg-white/[0.03] p-2">
                        <div class="flex min-w-0 items-center gap-2 text-[0.72rem]">
                            <p class="shrink-0 uppercase tracking-[0.18em] text-slate-400">Now</p>
                            <p data-audio-active-name class="shrink-0 font-semibold text-white">Ready</p>
                            <p data-audio-active-note class="shrink-0 uppercase tracking-[0.16em] text-cyan-300"></p>
                            <p data-audio-progress-label class="shrink-0 font-semibold text-white">00:00:00</p>
                        </div>

                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-800/80">
                            <div data-audio-progress class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-amber-300 transition-[width] duration-150"></div>
                        </div>
                        <p data-audio-support class="mt-1 text-[0.66rem] uppercase tracking-[0.16em] text-slate-500">Ready</p>
                        <div class="mt-1.5 flex flex-nowrap items-center gap-1">
                            <button type="button" data-live-continue class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">
                                Continue
                            </button>
                            <button type="button" data-live-retry class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-amber-300/20 bg-amber-300/10 px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-amber-100 transition hover:border-amber-300/30 hover:bg-amber-300/15 disabled:cursor-not-allowed disabled:opacity-50">
                                Retry
                            </button>
                            <button type="button" data-live-cancel class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-rose-400/20 bg-rose-400/10 px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15 disabled:cursor-not-allowed disabled:opacity-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                </section>

                <section class="relative z-0 shrink-0 rounded-lg border border-white/10 bg-slate-950/70 p-2.5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                    <div class="flex items-center justify-end">
                        <span data-live-cleaner-state class="rounded-lg border border-white/10 bg-white/[0.03] px-2 py-1 text-[0.65rem] uppercase tracking-[0.18em] text-slate-400">Waiting</span>
                    </div>

                    <div class="mt-2 rounded-lg border border-white/10 bg-white/[0.03] p-2">
                        <div class="flex items-center justify-between gap-3">
                            <p data-live-cleaner-progress-label class="text-[0.72rem] font-semibold text-white">0 / 0 batches</p>
                            <p data-live-cleaner-progress-percent class="text-[0.72rem] font-semibold text-cyan-200">0%</p>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-800/80">
                            <div data-live-cleaner-progress-bar class="h-full w-0 rounded-full bg-cyan-300 transition-all duration-300"></div>
                        </div>
                        <p data-live-cleaner-progress-note class="mt-1 text-[0.66rem] leading-4 text-slate-400">Record or load a raw transcript before polishing.</p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
