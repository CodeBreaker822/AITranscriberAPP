<x-app-layout title="Settings | AI Transcriber" active-page="settings">
    <div data-settings-workspace class="mx-auto max-w-4xl space-y-4">
        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Settings</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-white">Server license</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Save the server URL and license key. Providers, models, and languages are loaded from the hosted API.</p>
                </div>

                <span class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.24em] text-slate-400">
                    {{ $licenseStatusLabel }}
                </span>
            </div>

            @if (session('status'))
                <div class="mt-4 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-4 rounded-lg border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
                    {{ $errors->first() }}
                </div>
            @endif

            @if ($licenseRefreshError)
                <div class="mt-4 rounded-lg border border-amber-300/20 bg-amber-300/10 px-4 py-3 text-sm text-amber-100">
                    {{ $licenseRefreshError }}
                </div>
            @endif

            <form method="post" action="{{ route('settings.update') }}" class="mt-5 space-y-4" data-settings-form data-provider-models='@json($providerPayload, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'>
                @csrf

                <div class="grid gap-4 md:grid-cols-[0.9fr_1.1fr]">
                    <label class="block rounded-lg border border-white/10 bg-white/[0.03] p-4">
                        <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Server base URL</span>
                        <span class="mt-1 block text-sm leading-6 text-slate-400">Change this if the production domain moves.</span>
                        <input
                            type="text"
                            name="api_base_url"
                            value="{{ old('api_base_url', $apiBaseUrl) }}"
                            autocomplete="off"
                            placeholder="https://dilgaims.site/api"
                            class="mt-3 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                        >
                    </label>

                    <label class="block rounded-lg border border-white/10 bg-white/[0.03] p-4">
                        <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">License key</span>
                        <span class="mt-1 block text-sm leading-6 text-slate-400">This key connects AITranscriber to the hosted transcription API.</span>
                        @if ($licenseKeySuffix)
                            <span class="mt-2 block text-sm font-semibold text-slate-200">Saved license ending in {{ $licenseKeySuffix }}</span>
                        @endif
                        <input
                            type="password"
                            name="license_key"
                            value="{{ old('license_key') }}"
                            autocomplete="off"
                            placeholder="{{ $hasLicenseKey ? 'Paste a new license key to replace the saved one' : 'Paste your license key' }}"
                            class="mt-3 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                        >
                        <span class="mt-3 block text-sm leading-6 text-slate-300">{{ $licenseStatusMessage }}</span>
                    </label>
                </div>

                @if ($transcriptionProviders !== [])
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block rounded-lg border border-white/10 bg-white/[0.03] p-4">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Speech provider</span>
                            <select
                                name="speech_to_text_provider"
                                data-server-provider-select
                                class="mt-3 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                            >
                                @foreach ($transcriptionProviders as $provider)
                                    <option value="{{ $provider['provider'] }}" @selected(old('speech_to_text_provider', $selectedProvider) === $provider['provider'])>
                                        {{ $provider['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block rounded-lg border border-white/10 bg-white/[0.03] p-4">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Model</span>
                            <select
                                name="speech_to_text_model"
                                data-server-model-select
                                data-selected-model="{{ old('speech_to_text_model', $selectedModel) }}"
                                class="mt-3 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                            >
                                @foreach (($transcriptionProviders[$selectedProvider]['models'] ?? []) as $model)
                                    <option value="{{ $model['id'] }}" @selected(old('speech_to_text_model', $selectedModel) === $model['id'])>
                                        {{ $model['label'] ?? $model['id'] }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                @endif

                <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">CPU, RAM, and GPU usage</span>
                            <p class="mt-1 text-sm leading-6 text-slate-400">{{ $resourceProfile['gpu_available'] ? $resourceProfile['gpu_name'].' is available for compatible Whisper models.' : 'No compatible Whisper GPU was detected. Offline transcription will use CPU.' }}</p>
                        </div>
                        <span class="rounded-lg border border-white/10 bg-slate-950/70 px-2.5 py-1 text-[0.68rem] uppercase tracking-[0.18em] text-slate-400">
                            Auto: {{ $resourceProfile['auto_cpu_threads'] }} threads · {{ $resourceProfile['auto_memory_budget_mb'] }} MB RAM@if ($resourceProfile['gpu_available']) · {{ $resourceProfile['auto_gpu_vram_budget_mb'] }} MB VRAM@endif
                        </span>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Mode</span>
                            <select name="resource_mode" data-resource-mode class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                                <option value="auto" @selected(old('resource_mode', $resourceProfile['mode']) === 'auto')>Auto</option>
                                <option value="manual" @selected(old('resource_mode', $resourceProfile['mode']) === 'manual')>Manual</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">CPU threads</span>
                            <input type="number" min="1" max="{{ $resourceProfile['max_cpu_threads'] }}" name="resource_cpu_threads" data-resource-manual value="{{ old('resource_cpu_threads', $resourceProfile['cpu_threads']) }}" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                            <span class="mt-1 block text-xs text-slate-500">Auto uses {{ $resourceProfile['auto_cpu_threads'] }}. Max is {{ $resourceProfile['max_cpu_threads'] }}.</span>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">RAM budget MB</span>
                            <input type="number" min="{{ $resourceProfile['max_memory_budget_mb'] > 0 ? 1 : 0 }}" max="{{ $resourceProfile['max_memory_budget_mb'] }}" name="resource_memory_budget_mb" data-resource-manual value="{{ old('resource_memory_budget_mb', $resourceProfile['memory_budget_mb']) }}" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                            <span class="mt-1 block text-xs text-slate-500">Auto uses {{ $resourceProfile['auto_memory_budget_mb'] }} MB. Max is {{ $resourceProfile['max_memory_budget_mb'] }} MB.</span>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">GPU VRAM budget MB</span>
                            <input
                                type="number"
                                min="0"
                                max="{{ $resourceProfile['max_gpu_vram_budget_mb'] }}"
                                name="resource_gpu_vram_budget_mb"
                                data-resource-manual
                                data-resource-gpu-manual
                                data-gpu-available="{{ $resourceProfile['gpu_available'] ? 'true' : 'false' }}"
                                value="{{ old('resource_gpu_vram_budget_mb', $resourceProfile['gpu_vram_budget_mb']) }}"
                                @disabled(! $resourceProfile['gpu_available'] || old('resource_mode', $resourceProfile['mode']) !== 'manual')
                                class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-3 py-2.5 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                            <span class="mt-1 block text-xs text-slate-500">{{ $resourceProfile['gpu_available'] ? 'Auto uses '.$resourceProfile['auto_gpu_vram_budget_mb'].' MB. Max is '.$resourceProfile['max_gpu_vram_budget_mb'].' MB.' : 'CPU fallback active.' }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" data-settings-save class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-70">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                            <path d="M17 21v-8H7v8" />
                            <path d="M7 3v5h8" />
                        </svg>
                        Save and test
                    </button>
                </div>
            </form>
        </section>

        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Audio memory</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white">Memory controls</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Track temporary upload files and stored audio records created by live or uploaded transcription.</p>
                </div>

                <span class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.24em] text-slate-400">
                    {{ $audioMemory['total']['formatted_size'] }}
                </span>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-slate-100">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Total audio data</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $audioMemory['total']['formatted_size'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Temporary cache plus stored database audio.</p>
                    <form method="post" action="{{ route('settings.audio-memory.all.clear') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200" onclick="return confirm('Clear all audio data while keeping transcript text?')">
                            Clear all audio
                        </button>
                    </form>
                </article>

                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-slate-100">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Temporary upload cache</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $audioMemory['temporary']['formatted_size'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        {{ number_format($audioMemory['temporary']['sessions']) }} sessions,
                        {{ number_format($audioMemory['temporary']['files']) }} files
                    </p>
                </article>

                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-slate-100">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Stored audio records</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $audioMemory['stored']['formatted_size'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        {{ number_format($audioMemory['stored']['records']) }} records with audio data
                    </p>
                </article>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <h3 class="text-base font-semibold text-white">Clear temporary upload cache</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Removes uploaded source files and generated WAV sections left by cancelled or finished upload processing.</p>
                    <form method="post" action="{{ route('settings.audio-memory.temporary.clear') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200">
                            Clear cache
                        </button>
                    </form>
                </div>

                <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <h3 class="text-base font-semibold text-white">Clear stored audio data</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Removes saved live and uploaded audio bytes while keeping transcript text and record metadata.</p>
                    <form method="post" action="{{ route('settings.audio-memory.stored.clear') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200" onclick="return confirm('Clear stored audio data while keeping transcript text?')">
                            Clear stored audio
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Transcript memory</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white">Text cleanup</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Track raw transcript text, word timestamps, and polished transcript cache separately from audio data.</p>
                </div>

                <span class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.24em] text-slate-400">
                    {{ $transcriptMemory['total']['formatted_size'] }}
                </span>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-slate-100">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Total transcript text</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $transcriptMemory['total']['formatted_size'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Raw text, timestamps, and polished text cache.</p>
                </article>

                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-slate-100">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Raw transcripts</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $transcriptMemory['raw']['formatted_size'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">{{ number_format($transcriptMemory['raw']['records']) }} records</p>
                </article>

                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-slate-100">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Polished transcripts</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $transcriptMemory['cleaned']['formatted_size'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">{{ number_format($transcriptMemory['cleaned']['records']) }} records</p>
                </article>
            </div>

            <div class="mt-5 rounded-lg border border-white/10 bg-white/[0.03] p-4">
                <h3 class="text-base font-semibold text-white">Clear transcript text</h3>
                <p class="mt-2 text-sm leading-6 text-slate-400">Clears raw transcript text, word timestamps, and polished transcript rows while keeping stored audio records.</p>
                <form method="post" action="{{ route('settings.transcript-memory.clear') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200" onclick="return confirm('Clear all transcript text while keeping stored audio records?')">
                        Clear transcript text
                    </button>
                </form>
            </div>
        </section>
    </div>

</x-app-layout>
