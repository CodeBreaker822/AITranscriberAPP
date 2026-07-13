<x-app-layout title="Upload Audio | AI Transcriber" active-page="upload">
    <div data-workspace class="h-full min-h-0 overflow-hidden">
        <div data-workspace-grid class="grid h-full min-h-0 grid-rows-[1fr_auto] gap-3 lg:grid-cols-[1.35fr_0.65fr] lg:grid-rows-1">
            <section data-transcript-panel class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-white/10 bg-slate-950/70 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                <header class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Transcript</p>
                            <span data-upload-transcript-badge class="grid h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 text-[0.65rem] font-bold leading-none text-white">0</span>
                        </div>
                        <p data-upload-transcript-category class="mt-1 truncate text-sm text-slate-400">Upload audio</p>
                    </div>
                </header>

                <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-white/10 px-3 py-2">
                    <button type="button" data-furnish-upload class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-emerald-100 transition hover:border-emerald-300/30 hover:bg-emerald-300/15">
                        Polish
                    </button>
                    <button type="button" data-summarize="upload" class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-violet-300/20 bg-violet-300/10 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-violet-100 transition hover:border-violet-300/30 hover:bg-violet-300/15">
                        Summarize
                    </button>
                    <select data-export-upload-mode class="min-h-8 rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                        <option value="raw">Raw</option>
                        <option value="clean">Cleaned</option>
                    </select>
                    <select data-export-upload-format class="min-h-8 rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                        <option value="txt">TXT</option>
                        <option value="excel">Excel</option>
                        <option value="word">Microsoft Word</option>
                    </select>
                    <button type="button" data-export-upload class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 3v12" />
                            <path d="m7 10 5 5 5-5" />
                            <path d="M5 21h14" />
                        </svg>
                        Export
                    </button>
                    <button type="button" data-log-upload class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.12em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
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

                <div data-upload-transcript-list class="min-h-0 flex-1 overflow-y-auto px-4 py-2">
                    <div data-upload-transcript-empty class="w-full py-4">
                        <p class="text-sm text-slate-200">Transcript will appear here as each section finishes.</p>
                    </div>
                </div>
            </section>

            <aside data-workspace-sidebar class="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 overflow-visible">
                <section class="relative z-50 flex min-h-0 flex-col overflow-visible rounded-lg border border-white/10 bg-slate-950/70 p-2.5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                    <h1 class="text-xl font-semibold tracking-tight text-white">Audio to Text Converter</h1>

                    <form data-upload-form class="mt-2 space-y-2" action="#" method="post" enctype="multipart/form-data">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="relative z-50">
                                <label class="block">
                                    <span class="text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Project Name</span>
                                    <input type="text" name="category_name" data-upload-category placeholder="Project name" autocomplete="off" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1.5 text-[0.72rem] text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                                </label>
                                <div data-upload-category-suggestions class="absolute left-0 right-0 top-full z-50 mt-2 hidden max-h-48 overflow-y-auto rounded-lg border border-cyan-300/20 bg-slate-950 p-2 shadow-[0_20px_60px_rgba(0,0,0,0.55)]"></div>
                            </div>

                            <label class="block" data-language-control>
                                <span class="text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Language</span>
                                <select name="language_code" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1.5 text-[0.72rem] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20" data-upload-language>
                                    @foreach ($languageOptions as $option)
                                        <option value="{{ $option['value'] }}" @selected($loop->first)>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="hidden" data-whisper-model-control>
                                <span class="text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-slate-400">Whisper model</span>
                                <select name="whisper_model" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/80 px-2.5 py-1.5 text-[0.72rem] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20" data-whisper-model>
                                    @foreach ($whisperModels as $model)
                                        <option value="{{ $model['id'] }}" @selected($model['id'] === 'turbo')>{{ $model['label'] }} · {{ $model['size'] }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <input type="hidden" name="chunk_seconds" value="{{ $audioChunkSeconds }}" data-upload-chunk-size>

                        </div>

                        <div class="flex items-center gap-2 rounded-lg border border-dashed border-cyan-300/25 bg-cyan-300/5 p-2">
                            <label for="audio_file" class="inline-flex min-h-7 shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[0.66rem] font-semibold text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 3v12" />
                                    <path d="m7 8 5-5 5 5" />
                                    <path d="M5 21h14" />
                                </svg>
                                Browse file
                                <input id="audio_file" name="audio_file" type="file" accept="audio/*" class="sr-only" data-upload-file>
                            </label>
                            <div class="min-w-0 flex-1">
                                <p data-upload-file-name class="truncate text-[0.72rem] font-semibold text-white">Select an audio file</p>
                                <p data-upload-file-meta class="truncate text-[0.68rem] leading-4 text-slate-400">WAV, MP3, M4A, AAC, OGG, FLAC.</p>
                                <p class="text-[0.68rem] text-slate-400">Duration: <span data-upload-duration class="font-semibold text-slate-200">--:--</span></p>
                            </div>
                        </div>
                    </form>
                </section>

                <section class="relative z-0 shrink-0 rounded-lg border border-white/10 bg-slate-950/70 p-2.5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                    <div class="flex items-center justify-end">
                        <button type="button" data-open-sidebar="pending" aria-expanded="false" class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold text-slate-100 transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                            Pending <span data-upload-active-count class="text-cyan-300">0</span>
                        </button>
                    </div>

                    <div class="mt-2 rounded-lg border border-white/10 bg-white/[0.03] p-2">
                        <div class="flex min-w-0 items-center gap-2 text-[0.72rem]">
                            <p data-upload-status class="min-w-0 flex-1 truncate font-semibold text-white">Ready</p>
                            <p data-upload-progress-percent class="shrink-0 font-semibold text-white">0%</p>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-800/80">
                            <div data-upload-progress class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-amber-300 transition-[width] duration-150"></div>
                        </div>
                        <div data-upload-sherpa-progress class="mt-2 hidden border-t border-white/10 pt-2">
                            <div class="flex min-w-0 items-center gap-2 text-[0.68rem]">
                                <p data-upload-sherpa-status class="min-w-0 flex-1 truncate font-semibold text-slate-200">Waiting</p>
                                <p data-upload-sherpa-percent class="shrink-0 font-semibold text-cyan-200">0%</p>
                            </div>
                            <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-slate-800/80">
                                <div data-upload-sherpa-bar class="h-full w-0 rounded-full bg-cyan-300 transition-[width] duration-300"></div>
                            </div>
                        </div>
                        <div class="mt-1.5 flex flex-nowrap items-center gap-1">
                            <button type="button" data-upload-queue class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg bg-cyan-300 px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:bg-slate-600 disabled:text-slate-300">
                                Start
                            </button>
                            <button type="button" data-upload-pause class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">
                                Pause
                            </button>
                            <button type="button" data-upload-continue class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">
                                Continue
                            </button>
                            <button type="button" data-upload-retry class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-amber-300/20 bg-amber-300/10 px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-amber-100 transition hover:border-amber-300/30 hover:bg-amber-300/15 disabled:cursor-not-allowed disabled:opacity-50">
                                Retry
                            </button>
                            <button type="button" data-upload-cancel class="inline-flex min-h-7 flex-1 cursor-pointer items-center justify-center rounded-lg border border-rose-400/20 bg-rose-400/10 px-1.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.08em] text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15 disabled:cursor-not-allowed disabled:opacity-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                </section>

                <section class="relative z-0 shrink-0 rounded-lg border border-white/10 bg-slate-950/70 p-2.5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
                    <div class="flex items-center justify-end">
                        <span data-cleaner-state class="rounded-lg border border-white/10 bg-white/[0.03] px-2 py-1 text-[0.65rem] uppercase tracking-[0.18em] text-slate-400">Waiting</span>
                    </div>

                    <div class="mt-2 rounded-lg border border-white/10 bg-white/[0.03] p-2">
                        <div class="flex items-center justify-between gap-3">
                            <p data-cleaner-progress-label class="text-[0.72rem] font-semibold text-white">0 / 0 batches</p>
                            <p data-cleaner-progress-percent class="text-[0.72rem] font-semibold text-cyan-200">0%</p>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-800/80">
                            <div data-cleaner-progress-bar class="h-full w-0 rounded-full bg-cyan-300 transition-all duration-300"></div>
                        </div>
                        <p data-cleaner-progress-note class="mt-1 text-[0.66rem] leading-4 text-slate-400">The polished transcript will be prepared after raw transcription is ready.</p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
