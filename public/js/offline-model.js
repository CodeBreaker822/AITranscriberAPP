(() => {
    'use strict';

    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    };

    ready(() => {
        const body = document.body;
        const downloadButton = document.querySelector('[data-offline-model-download]');
        const engineSwitch = document.querySelector('[data-transcription-engine-switch]');
        const engineToggle = document.querySelector('[data-transcription-engine-toggle]');
        const dialog = document.querySelector('[data-offline-model-dialog]');
        const expanded = document.querySelector('[data-offline-model-expanded]');
        const compact = document.querySelector('[data-offline-model-compact]');
        const backdrop = document.querySelector('[data-offline-model-backdrop]');
        const minimizeButton = document.querySelector('[data-offline-model-minimize]');
        const closeButton = document.querySelector('[data-offline-model-close]');
        const retryButton = document.querySelector('[data-offline-model-retry]');
        const actions = document.querySelector('[data-offline-model-actions]');
        const cancelActions = document.querySelector('[data-offline-model-cancel-actions]');
        const cancelButton = document.querySelector('[data-offline-model-cancel]');
        const progress = document.querySelector('[data-offline-model-progress]');
        const downloadNote = document.querySelector('[data-offline-model-download-note]');
        const title = document.querySelector('[data-offline-model-title]');
        const message = document.querySelector('[data-offline-model-message]');
        const compactLabel = document.querySelector('[data-offline-model-compact-label]');
        const progressLabels = document.querySelectorAll('[data-offline-model-progress-label]');
        const progressPercents = document.querySelectorAll('[data-offline-model-progress-percent]');
        const progressBars = document.querySelectorAll('[data-offline-model-progress-bar]');
        const statusUrl = String(body?.dataset.offlineModelStatusUrl || '');
        const downloadUrl = String(body?.dataset.offlineModelDownloadUrl || '');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        if (!downloadButton || !engineSwitch || !dialog || !expanded || !statusUrl || !downloadUrl) {
            return;
        }

        let currentStatus = { installed: false, default_model: 'turbo', models: [] };
        let activeModel = 'turbo';
        let downloading = false;
        let downloadController = null;
        let downloadRun = 0;
        let modelList = dialog.querySelector('[data-offline-model-list]');

        if (!modelList) {
            modelList = document.createElement('div');
            modelList.dataset.offlineModelList = '';
            modelList.className = 'mt-3 min-h-0 overflow-y-auto rounded-lg border border-white/10 bg-white/[0.02]';
            message.insertAdjacentElement('afterend', modelList);
        }

        const setVisible = (element, visible, displayClass) => {
            element.hidden = !visible;
            element.classList.toggle('hidden', !visible);

            if (displayClass) {
                element.classList.toggle(displayClass, visible);
            }
        };

        const installedModels = (status) => (Array.isArray(status?.models) ? status.models : [])
            .filter((model) => model?.kind !== 'diarization' && model?.installed === true && model?.supported !== false);

        const syncHeader = (status) => {
            const hasModel = installedModels(status).length > 0;

            setVisible(downloadButton, !hasModel, 'inline-flex');
            setVisible(engineSwitch, hasModel, 'flex');
            engineToggle?.toggleAttribute('disabled', !hasModel);
            const headerLabel = downloadButton.querySelector('[data-offline-model-label]');
            if (headerLabel) {
                headerLabel.textContent = 'Download Offline';
            }

            if (!hasModel && engineToggle) {
                engineToggle.checked = false;
                engineToggle.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        const dispatchStatus = (status) => {
            window.dispatchEvent(new CustomEvent('offline-model:status', { detail: status }));
        };

        const updateProgress = (percent, label = 'Downloading') => {
            const normalized = Math.max(0, Math.min(100, Number(percent) || 0));

            progressLabels.forEach((element) => { element.textContent = label; });
            progressPercents.forEach((element) => { element.textContent = `${Math.round(normalized)}%`; });
            progressBars.forEach((element) => { element.style.width = `${normalized}%`; });
        };

        const renderModels = () => {
            const models = (Array.isArray(currentStatus.models) ? currentStatus.models : [])
                .filter((model) => model?.kind !== 'diarization');

            modelList.innerHTML = '';

            models.forEach((model) => {
                const installed = model.installed === true;
                const supported = model.supported !== false;
                const option = document.createElement('div');
                option.dataset.offlineModelOption = model.id;
                option.className = 'flex min-w-0 items-center justify-between gap-4 border-b border-white/10 px-3 py-2.5 transition last:border-b-0 hover:bg-white/[0.03]';
                option.innerHTML = `
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white"></span>
                        <span class="mt-0.5 block text-xs text-slate-400"></span>
                    </span>
                    <button type="button" class="min-w-[5.5rem] shrink-0 cursor-pointer rounded-md border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-default ${
                        installed
                            ? 'border-emerald-300/20 bg-emerald-300/10 text-emerald-200'
                            : supported
                                ? 'border-cyan-300/30 bg-cyan-300 text-slate-950 hover:bg-cyan-200'
                                : 'border-white/10 bg-white/[0.03] text-slate-500'
                    }"></button>
                `;
                option.querySelector('span span:first-child').textContent = String(model.label || model.id || 'Local model');
                const memory = Number(model.runtime_memory_mb || 0);
                const unsupportedReason = String(model.unsupported_reason || '');
                option.querySelector('span span:nth-child(2)').textContent = supported
                    ? `${String(model.size || 'Offline model')}${memory > 0 ? ` · ~${memory} MB RAM` : ''}`
                    : unsupportedReason || `${String(model.size || 'Offline model')} · exceeds safe RAM budget`;
                const optionButton = option.querySelector('button');
                optionButton.textContent = installed ? 'Installed' : supported ? 'Download' : unsupportedReason ? 'Unavailable' : 'Low memory';
                optionButton.disabled = downloading || installed || !supported;
                optionButton.setAttribute('aria-label', `${optionButton.textContent} ${String(model.label || model.id || 'model')}`);
                optionButton.addEventListener('click', () => startDownload(String(model.id || 'turbo')));
                modelList.appendChild(option);
            });

            if (models.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'rounded-lg border border-amber-300/20 bg-amber-300/10 px-4 py-3 text-sm text-amber-100';
                empty.textContent = 'The offline model catalog could not be loaded. Please try again.';
                modelList.appendChild(empty);
            }
        };

        const showDialog = () => {
            dialog.hidden = false;
            dialog.classList.remove('hidden');
            dialog.classList.add('pointer-events-none');
            dialog.setAttribute('aria-hidden', 'false');
            setVisible(backdrop, true);
            setVisible(expanded, true);
            setVisible(compact, false, 'flex');
        };

        const hideDialog = () => {
            if (downloading) {
                setVisible(backdrop, false);
                setVisible(expanded, false);
                setVisible(compact, true, 'flex');
                return;
            }

            dialog.hidden = true;
            dialog.classList.add('hidden', 'pointer-events-none');
            dialog.setAttribute('aria-hidden', 'true');
        };

        const showCatalog = () => {
            downloading = false;
            title.textContent = 'Download Offline Whisper';
            message.textContent = 'Choose a Whisper model for offline transcription. Speaker Separation is already included with AITranscriber.';
            compactLabel.textContent = 'Local model download';
            setVisible(modelList, true);
            setVisible(actions, false, 'flex');
            setVisible(cancelActions, false, 'flex');
            setVisible(progress, false);
            setVisible(downloadNote, false);
            renderModels();
            showDialog();
        };

        const refreshStatus = async ({ safeFallback = false } = {}) => {
            try {
                const response = await fetch(statusUrl, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error(`Status request failed with HTTP ${response.status}.`);
                }

                const status = await response.json();
                currentStatus = {
                    installed: status?.installed === true,
                    default_model: String(status?.default_model || 'turbo'),
                    models: Array.isArray(status?.models) ? status.models : [],
                    diarization_installed: status?.diarization_installed === true,
                };
                activeModel = activeModel || currentStatus.default_model;
                syncHeader(currentStatus);
                renderModels();
                dispatchStatus(currentStatus);

                return currentStatus;
            } catch (error) {
                if (safeFallback) {
                    currentStatus = { installed: false, default_model: 'turbo', models: [] };
                    dispatchStatus(currentStatus);
                }

                throw error;
            }
        };

        const handleDownloadEvent = async (event) => {
            if (event.type === 'progress') {
                const total = Number(event.total_bytes || 0);
                const received = Number(event.received_bytes || 0);
                updateProgress(total > 0 ? (received / total) * 100 : 0, 'Downloading');
                return;
            }

            if (event.type === 'source') {
                message.textContent = `Downloading ${String(event.asset || activeModel)} from ${String(event.host || 'the model server')}.`;
                return;
            }

            if (event.type === 'error') {
                throw new Error(String(event.message || 'Offline model download failed.'));
            }

            if (event.type === 'complete') {
                updateProgress(100, 'Installed');
            }
        };

        async function startDownload(model) {
            if (downloading) {
                return;
            }

            activeModel = model || currentStatus.default_model || 'turbo';
            downloading = true;
            const run = ++downloadRun;
            const controller = new AbortController();
            downloadController = controller;
            title.textContent = `Installing ${activeModel}`;
            message.textContent = 'Connecting to the offline model server.';
            compactLabel.textContent = `Installing ${activeModel}`;
            setVisible(modelList, false);
            setVisible(actions, false, 'flex');
            setVisible(cancelActions, true, 'flex');
            setVisible(progress, true);
            setVisible(downloadNote, true);
            updateProgress(0, 'Connecting');
            renderModels();
            showDialog();

            try {
                const response = await fetch(downloadUrl, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/x-ndjson, application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: new URLSearchParams({ model: activeModel }),
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    throw new Error(String(payload.message || `Download failed with HTTP ${response.status}.`));
                }

                const contentType = response.headers.get('content-type') || '';

                if (contentType.includes('application/json')) {
                    currentStatus = await response.json();
                } else if (response.body) {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';

                    while (true) {
                        const { value, done } = await reader.read();
                        buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
                        const lines = buffer.split(/\r?\n/);
                        buffer = lines.pop() || '';

                        for (const line of lines) {
                            if (line.trim()) {
                                await handleDownloadEvent(JSON.parse(line));
                            }
                        }

                        if (done) {
                            if (buffer.trim()) {
                                await handleDownloadEvent(JSON.parse(buffer));
                            }
                            break;
                        }
                    }
                }

                if (run !== downloadRun) {
                    return;
                }

                downloading = false;
                downloadController = null;
                await refreshStatus();
                title.textContent = 'Local model installed';
                message.textContent = 'Offline transcription is ready. You can choose the installed Whisper model from the transcription controls.';
                setVisible(actions, true, 'flex');
                setVisible(cancelActions, false, 'flex');
                retryButton?.classList.add('hidden');
                window.dispatchEvent(new CustomEvent('offline-model:installed', { detail: currentStatus }));
            } catch (error) {
                if (run !== downloadRun || error?.name === 'AbortError') {
                    return;
                }

                downloading = false;
                downloadController = null;
                title.textContent = 'Download failed';
                message.textContent = String(error?.message || 'Offline model download failed. Please try again.');
                setVisible(actions, true, 'flex');
                setVisible(cancelActions, false, 'flex');
                retryButton?.classList.remove('hidden');
                renderModels();
            }
        }

        const cancelDownload = () => {
            if (!downloading) {
                return;
            }

            downloadRun += 1;
            downloading = false;
            downloadController?.abort();
            downloadController = null;
            title.textContent = 'Download canceled';
            message.textContent = 'The offline model download was canceled. You can retry whenever you are ready.';
            compactLabel.textContent = 'Download canceled';
            updateProgress(0, 'Canceled');
            setVisible(cancelActions, false, 'flex');
            setVisible(actions, true, 'flex');
            retryButton?.classList.remove('hidden');
            renderModels();
            showDialog();
        };

        const openCatalog = async (model = '') => {
            if (downloading) {
                showDialog();
                return;
            }

            if (model) {
                activeModel = model;
            }

            try {
                await refreshStatus();
            } catch {
                // The catalog renders a safe retry state below.
            }
            showCatalog();
        };

        downloadButton.addEventListener('click', () => openCatalog());
        minimizeButton?.addEventListener('click', hideDialog);
        compact?.addEventListener('click', showDialog);
        closeButton?.addEventListener('click', hideDialog);
        backdrop?.addEventListener('click', hideDialog);
        retryButton?.addEventListener('click', () => startDownload(activeModel));
        cancelButton?.addEventListener('click', cancelDownload);
        window.addEventListener('offline-model:catalog-request', (event) => {
            const model = String(event.detail?.model || currentStatus.default_model || 'turbo');
            openCatalog(model);
        });

        // Blade renders the initial header state so navigation does not wait on JavaScript.
        refreshStatus({ safeFallback: true }).catch(() => {});
    });
})();
