$(function () {
    const $body = $('body');
    const browserDownloadTextFile = (filename, content) => {
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    };

    const saveTextExport = async (filename, content) => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function') {
            browserDownloadTextFile(filename, content);
            return;
        }

        try {
            const path = await invoke('save_text_export_with_dialog', { content });

            if (path && typeof window.showNotification === 'function') {
                window.showNotification(`Export saved to ${path}`, 'success');
            }
        } catch (error) {
            if (typeof window.showNotification === 'function') {
                const message = String(error || '').trim();
                window.showNotification(message || 'Could not save the export. Please try again.', 'error');
            }
        }
    };

    const openExternalLink = async (url) => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function') {
            window.open(url, '_blank', 'noopener,noreferrer');
            return;
        }

        await invoke('open_external_url', { url });
    };

    const requestPolishInstructions = () => typeof window.requestPolishInstructions === 'function'
        ? window.requestPolishInstructions()
        : Promise.resolve(null);

    const speakerSessionReleaseUrl = String($body.attr('data-speaker-session-release-url') || '');
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
    const createSpeakerSessionId = () => window.crypto?.randomUUID?.()
        || `speaker-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const createTranscriptionProgressId = () => window.crypto?.randomUUID?.()
        || `progress-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const clampProgressPercent = (value) => Math.max(0, Math.min(100, Number(value || 0)));
    const createPhaseProgress = (definitions) => Object.fromEntries(
        definitions.map((phase) => [phase.key, 0]),
    );
    const phaseProgressAverage = (phases, definitions) => {
        const keys = definitions.map((phase) => phase.key);

        if (!keys.length) {
            return 0;
        }

        const total = keys.reduce((sum, key) => sum + clampProgressPercent(phases?.[key]), 0);

        return Math.round(total / keys.length);
    };
    const phaseProgressSummary = (phases, definitions) => definitions
        .map((phase) => `${phase.label} ${Math.round(clampProgressPercent(phases?.[phase.key]))}%`)
        .join(' | ');
    const whisperProgressHandlers = new Map();
    const registerWhisperProgress = (progressId, callback) => {
        if (progressId) {
            whisperProgressHandlers.set(progressId, callback);
        }
    };
    const clearWhisperProgress = (progressId) => {
        if (progressId) {
            whisperProgressHandlers.delete(progressId);
        }
    };
    const cancelWhisperProgress = (progressId) => {
        const normalized = String(progressId || '').trim();

        if (!normalized) {
            return;
        }

        clearWhisperProgress(normalized);
        const invoke = window.__TAURI__?.core?.invoke;
        if (typeof invoke === 'function') {
            invoke('cancel_offline_whisper', { progressId: normalized }).catch(() => {});
        }
    };
    const tauriEventListen = window.__TAURI__?.event?.listen;
    if (typeof tauriEventListen === 'function') {
        tauriEventListen('offline-whisper-progress', (event) => {
            const progressId = String(event?.payload?.progress_id || '');
            const percent = Math.max(0, Math.min(100, Number(event?.payload?.percent) || 0));
            whisperProgressHandlers.get(progressId)?.(percent);
        }).catch(() => {});
    }
    const releaseSpeakerSession = (sessionId, useBeacon = false) => {
        const normalized = String(sessionId || '').trim();

        if (!normalized || !speakerSessionReleaseUrl) {
            return;
        }

        const data = new FormData();
        data.append('speaker_session_id', normalized);
        if (csrfToken) {
            data.append('_token', csrfToken);
        }

        if (useBeacon && typeof navigator.sendBeacon === 'function') {
            navigator.sendBeacon(speakerSessionReleaseUrl, data);
            return;
        }

        $.ajax({
            url: speakerSessionReleaseUrl,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
        });
    };

    const escapeTranscriptHtml = (value) => $('<div>').text(String(value || '')).html();

    const speakerLabel = (speakerId) => {
        const match = String(speakerId || '').match(/(\d+)$/);

        return match ? `Speaker ${Math.max(1, Number(match[1]))}` : 'Speaker';
    };

    const appendTranscriptPart = (current, part) => {
        const text = String(part || '').trim();

        if (!current || !text) {
            return current || text;
        }

        return /^[.,!?;:%)\]}]/u.test(text) || /[(\[{]$/u.test(current)
            ? `${current}${text}`
            : `${current} ${text}`;
    };

    const renderTranscriptText = (text, timestamps = []) => {
        const entries = Array.isArray(timestamps) ? timestamps : [];
        const turns = [];

        entries.forEach((entry) => {
            const part = String(entry?.text || '').trim();
            const speakerId = String(entry?.speaker_id || entry?.speakerId || '').trim();

            if (!part || !speakerId) {
                return;
            }

            const previous = turns[turns.length - 1];

            if (previous?.speakerId === speakerId) {
                previous.text = appendTranscriptPart(previous.text, part);
                return;
            }

            turns.push({ speakerId, text: part });
        });

        if (!turns.length) {
            return `<p class="whitespace-pre-line break-words text-xs leading-5 text-slate-100">${escapeTranscriptHtml(text)}</p>`;
        }

        return `
            <div class="space-y-1.5" data-speaker-turns>
                ${turns.map((turn) => `
                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-2" data-speaker-id="${escapeTranscriptHtml(turn.speakerId)}">
                        <span class="whitespace-nowrap text-xs font-semibold leading-5 text-cyan-300">${escapeTranscriptHtml(speakerLabel(turn.speakerId))}:</span>
                        <span class="break-words text-xs leading-5 text-slate-100">${escapeTranscriptHtml(turn.text)}</span>
                    </div>
                `).join('')}
            </div>
        `;
    };

    const $transcriptionEngine = $('[data-transcription-engine-toggle]');
    const $transcriptionEngineSwitch = $('[data-transcription-engine-switch]');
    const $languageControls = $('[data-language-control]');
    const $whisperModelControls = $('[data-whisper-model-control]');
    const $whisperModel = $('[data-whisper-model]');
    const $onlineOnlyTranscriptActions = $('[data-furnish-live], [data-furnish-upload], [data-summarize]');
    const transcriptionEngineStorageKey = 'ai-transcriber-transcription-engine';
    const whisperModelStorageKey = 'ai-transcriber-whisper-model';
    const connectivityUrl = String($body.attr('data-update-connectivity-url') || '');
    let engineConnectivityRequest = null;
    let installedWhisperModels = new Set();

    const getTranscriptionEngine = () => $transcriptionEngine.prop('checked') ? 'offline' : 'online';
    const getWhisperModel = () => String($whisperModel.val() || 'turbo');

    const syncTranscriptionControls = () => {
        const offline = getTranscriptionEngine() === 'offline';
        $languageControls.toggleClass('hidden', offline);
        $whisperModelControls.toggleClass('hidden', !offline);
        $onlineOnlyTranscriptActions.toggleClass('hidden', offline);
    };

    const applyEngineAvailability = (connectivity) => {
        const online = connectivity?.online === true && navigator.onLine !== false;
        const offlineAvailable = connectivity?.offline_available === true;
        $transcriptionEngine.prop('disabled', !offlineAvailable);
        $transcriptionEngineSwitch.toggleClass('hidden', !offlineAvailable)
            .toggleClass('flex', offlineAvailable);

        if (!online && offlineAvailable) {
            $transcriptionEngine.prop('checked', true);
        } else if (!offlineAvailable && online) {
            $transcriptionEngine.prop('checked', false);
        } else if (online && offlineAvailable) {
            const preferred = window.localStorage.getItem(transcriptionEngineStorageKey);
            $transcriptionEngine.prop('checked', preferred === 'offline');
        }

        const modelMissing = connectivity?.offline_model_available === false;
        const availability = offlineAvailable
            ? 'Offline Whisper is ready.'
            : modelMissing
                ? 'Install the large-v3-turbo Q8 model to enable offline transcription.'
                : 'Offline transcription is available in the desktop app.';
        $transcriptionEngineSwitch.attr('title', availability);
        $transcriptionEngine.trigger('transcription-engine:availability', [{ online, offlineAvailable }]);
        syncTranscriptionControls();
    };

    const refreshEngineAvailability = () => {
        if (!$transcriptionEngine.length || !connectivityUrl || engineConnectivityRequest) {
            return;
        }

        engineConnectivityRequest = $.ajax({
            url: connectivityUrl,
            method: 'GET',
            cache: false,
        })
            .done(applyEngineAvailability)
            .always(() => {
                engineConnectivityRequest = null;
            });
    };

    if ($transcriptionEngine.length) {
        $transcriptionEngine.on('change', function () {
            window.localStorage.setItem(transcriptionEngineStorageKey, getTranscriptionEngine());
            syncTranscriptionControls();
        });
        const preferredModel = window.localStorage.getItem(whisperModelStorageKey);
        if (preferredModel) {
            $whisperModel.val(preferredModel);
        }
        $whisperModel.on('change', function () {
            const model = getWhisperModel();
            window.localStorage.setItem(whisperModelStorageKey, model);
            if (!installedWhisperModels.has(model)) {
                window.dispatchEvent(new CustomEvent('offline-model:catalog-request', { detail: { model } }));
            }
        });
        window.addEventListener('offline-model:status', (event) => {
            const models = (Array.isArray(event.detail?.models) ? event.detail.models : [])
                .filter((model) => model.kind !== 'diarization');
            installedWhisperModels = new Set(models
                .filter((model) => model.installed && model.supported !== false)
                .map((model) => model.id));
            models.forEach((model) => {
                const supported = model.supported !== false;
                const suffix = !supported ? 'Low memory' : model.installed ? 'Installed' : 'Download';
                $whisperModel.find(`option[value="${model.id}"]`)
                    .text(`${model.label} · ${model.size} · ${suffix}`)
                    .prop('disabled', !supported);
            });

            if (!installedWhisperModels.has(getWhisperModel())) {
                const fallback = models.find((model) => model.installed && model.supported !== false)?.id;
                if (fallback) {
                    $whisperModel.val(fallback);
                    window.localStorage.setItem(whisperModelStorageKey, fallback);
                }
            }
        });
        window.addEventListener('online', refreshEngineAvailability);
        window.addEventListener('offline', refreshEngineAvailability);
        window.addEventListener('offline-model:installed', refreshEngineAvailability);
        refreshEngineAvailability();
        window.setInterval(refreshEngineAvailability, 30000);
        syncTranscriptionControls();
    }

    $(document).on('click', 'a[target="_blank"]', function (event) {
        const href = String($(this).attr('href') || '').trim();

        if (!/^https?:\/\//i.test(href)) {
            return;
        }

        event.preventDefault();

        openExternalLink(href).catch(() => {
            window.open(href, '_blank', 'noopener,noreferrer');
        });
    });

    if ($body.data('page') === 'upload') {
        const uploadFrontendVersion = 'upload-flow-v4-queue-parity';
        const uploadStateStorageKey = 'ai-transcriber-upload-session';
        const notify = (message, type = 'success') => {
            if (typeof window.showNotification === 'function') {
                window.showNotification(message, type);
            }
        };
        const notifyError = (message) => notify(message, 'error');
        const pause = (milliseconds) => new Promise((resolve) => {
            window.setTimeout(resolve, milliseconds);
        });
        const tauriInvoke = () => window.__TAURI__?.core?.invoke;

        if (csrfToken) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
        }

        const uploadUrl = String($body.attr('data-upload-audio-url') || '');
        const uploadPrepareUrl = String($body.attr('data-upload-audio-prepare-url') || '');
        const uploadPrepareBatchUrl = String($body.attr('data-upload-audio-prepare-batch-url') || '');
        const uploadDiarizeUrl = String($body.attr('data-upload-audio-diarize-url') || '');
        const uploadSessionStatusUrl = String($body.attr('data-upload-session-status-url') || '');
        const audioChunkUrl = String($body.attr('data-audio-chunk-url') || '');
        const audioChunkBatchUrl = String($body.attr('data-audio-chunk-batch-url') || '');
        const maxTranscribeBatchDurationMs = Math.max(1, Number($body.attr('data-transcribe-max-batch-duration-ms') || 1200000) || 1200000);
        const maxTranscribeBatchClips = Math.max(1, Number($body.attr('data-transcribe-max-batch-clips') || 20) || 20);
        const storedUrl = String($body.attr('data-stored-url') || '');
        const vadLogUrl = String($body.attr('data-vad-log-url') || '');
        const furnishUrl = String($body.attr('data-furnish-url') || '');
        const defaultUserId = Number($body.attr('data-default-user-id') || 1);
        const $form = $('[data-upload-form]');
        const $categoryInput = $('[data-upload-category]');
        const $categorySuggestions = $('[data-upload-category-suggestions]');
        const $languageInput = $('[data-upload-language]');
        const $fileInput = $('[data-upload-file]');
        const $fileName = $('[data-upload-file-name]');
        const $fileMeta = $('[data-upload-file-meta]');
        const $duration = $('[data-upload-duration]');
        const $status = $('[data-upload-status]');
        const $chunkSize = $('[data-upload-chunk-size]');
        const $queueButton = $('[data-upload-queue]');
        const $pauseButton = $('[data-upload-pause]');
        const $continueButton = $('[data-upload-continue]');
        const $retryButton = $('[data-upload-retry]');
        const $cancelButton = $('[data-upload-cancel]');
        const $queueList = $('[data-upload-queue-list]');
        const $activeCount = $('[data-upload-active-count]');
        const $progress = $('[data-upload-progress]');
        const $progressPercent = $('[data-upload-progress-percent]');
        const $sherpaProgress = $('[data-upload-sherpa-progress]');
        const $sherpaStatus = $('[data-upload-sherpa-status]');
        const $sherpaPercent = $('[data-upload-sherpa-percent]');
        const $sherpaBar = $('[data-upload-sherpa-bar]');
        const $transcriptCategory = $('[data-upload-transcript-category]');
        const $transcriptBadge = $('[data-upload-transcript-badge]');
        const $transcriptList = $('[data-upload-transcript-list]');
        const $exportButton = $('[data-export-upload]');
        const $exportMode = $('[data-export-upload-mode]');
        const $logButton = $('[data-log-upload]');
        const $furnishButton = $('[data-furnish-upload]');
        const $cleanerState = $('[data-cleaner-state]');
        const $cleanerProgressLabel = $('[data-cleaner-progress-label]');
        const $cleanerProgressPercent = $('[data-cleaner-progress-percent]');
        const $cleanerProgressBar = $('[data-cleaner-progress-bar]');
        const $cleanerProgressNote = $('[data-cleaner-progress-note]');

        let selectedFile = null;
        let selectedDurationMs = 0;
        let preparedSections = [];
        let metadataUrl = null;
        let uploadInFlight = false;
        let uploadCancelling = false;
        let currentSessionId = '';
        let currentCategoryName = '';
        let activeBatchPositions = [];
        let cancelRequested = false;
        let pauseRequested = false;
        let sourceUploadXhr = null;
        let activePrepareXhrs = [];
        let activeSectionXhr = null;
        let activeSectionPosition = 0;
        let activeSectionProgress = 0;
        let activeSectionProgressTimer = null;
        let activeSectionProgressId = '';
        let activeUploadPhaseProgress = null;
        let activeUploadPhaseDefinitions = [];
        let sherpaProgressActive = false;
        let activeDiarizationMonitorGeneration = 0;
        let cleanedSections = [];
        let furnishInFlight = false;
        let cleanerStatus = 'Waiting';
        let cleanerCompletedBatches = 0;
        let uploadCategories = [];
        let uploadStoredItems = [];
        let uploadCleanedCategoryName = '';
        let activeUploadAudio = null;
        let activeUploadAudioId = null;
        let uploadShowingVadLogs = false;

        const formatClock = (milliseconds) => {
            const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;

            if (hours > 0) {
                return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }

            return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        };

        const formatBytes = (bytes) => {
            if (!Number.isFinite(bytes) || bytes <= 0) {
                return '0 MB';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            const value = bytes / (1024 ** index);

            return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[index]}`;
        };

        const formatRelativeClock = (milliseconds) => `+${formatClock(milliseconds)}`;

        const getChunkLengthMs = () => Math.min(maxTranscribeBatchDurationMs, Number($chunkSize.val() || 300) * 1000);

        const clampUploadChunkSize = () => {
            const selectedSeconds = Number($chunkSize.val() || 300);
            const maxSeconds = Math.floor(maxTranscribeBatchDurationMs / 1000);

            if (selectedSeconds > maxSeconds) {
                $chunkSize.val(String(Math.min(1200, maxSeconds)));
            }
        };

        const getUploadPrepareConcurrency = () => Math.max(1, Math.min(
            preparedSections.length || 1,
            Number($body.attr('data-resource-cpu-threads') || window.navigator?.hardwareConcurrency || 1) || 1,
        ));

        const slugify = (value) => String(value || 'transcription')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'transcription';

        const escapeHtml = (value) => $('<div>').text(String(value || '')).html();

        const hasUsefulTranscriptText = (value) => {
            const normalized = String(value || '').trim().toLowerCase();

            return normalized !== '' && normalized !== 'no speech detected' && normalized !== 'no speech detected.';
        };

        const hasUsefulUploadTranscript = (item) => hasUsefulTranscriptText(
            item?.translatedText || item?.translated_text || item?.text || '',
        );

        const downloadTextFile = (filename, content) => {
            saveTextExport(filename, content);
        };

        const getUploadCategory = () => String($categoryInput.val() || '').trim();

        const getUploadLanguageCode = () => getTranscriptionEngine() === 'offline'
            ? 'auto'
            : String($languageInput.val() || 'multi').trim() || 'multi';

        const hasCleanedUploadTranscriptForCategory = (categoryName) => (
            uploadCleanedCategoryName
            && String(uploadCleanedCategoryName).toLowerCase() === String(categoryName || '').toLowerCase()
        );

        const syncTranscriptCategory = () => {
            $transcriptCategory.text(getUploadCategory() || selectedFile?.name || 'Upload audio');
        };

        const saveUploadState = () => {
            if (!currentSessionId) {
                return;
            }

            try {
                window.localStorage.setItem(uploadStateStorageKey, JSON.stringify({
                    sessionId: currentSessionId,
                    categoryName: currentCategoryName || getUploadCategory(),
                    durationMs: selectedDurationMs,
                    sections: preparedSections,
                    savedAt: Date.now(),
                }));
            } catch (error) {
            }
        };

        const clearUploadState = () => {
            try {
                window.localStorage.removeItem(uploadStateStorageKey);
            } catch (error) {
            }
        };

        const hasRetryableSections = () => preparedSections.some((section) => ['Failed', 'Cancelled'].includes(section.status));

        const hasUnfinishedSections = () => preparedSections.some((section) => section.status !== 'Complete');

        const hasUploadProgress = () => preparedSections.length > 0;

        const uploadOnlinePhaseDefinitions = [
            { key: 'prepare', label: 'Prepare' },
            { key: 'silero', label: 'Silero' },
            { key: 'transcribe', label: 'Whisper' },
        ];
        const uploadOfflinePhaseDefinitions = [
            { key: 'prepare', label: 'Prepare' },
            { key: 'silero', label: 'Silero' },
            { key: 'transcribe', label: 'Server/Whisper' },
            { key: 'sherpa', label: 'Diarization' },
        ];
        const uploadSherpaPhaseDefinitions = [
            { key: 'sherpa', label: 'Diarization' },
        ];
        const getUploadPhaseDefinitions = () => getTranscriptionEngine() === 'offline'
            ? uploadOfflinePhaseDefinitions
            : uploadOnlinePhaseDefinitions;
        const resetUploadPhaseProgress = () => {
            activeUploadPhaseProgress = null;
            activeUploadPhaseDefinitions = [];
            $progressPercent.text('0%');
            $progress.css('width', '0%');
            $status.removeAttr('title');
        };
        const beginUploadPhaseProgress = (label = 'Processing') => {
            activeUploadPhaseDefinitions = getUploadPhaseDefinitions();
            activeUploadPhaseProgress = createPhaseProgress(activeUploadPhaseDefinitions);
            $status.text(label);
            $status.attr('title', phaseProgressSummary(activeUploadPhaseProgress, activeUploadPhaseDefinitions));
            $progressPercent.text('0%');
            $progress.css('width', '0%');
        };
        const renderUploadPhaseProgress = (label = '') => {
            if (!activeUploadPhaseProgress || !activeUploadPhaseDefinitions.length) {
                return false;
            }

            const percent = phaseProgressAverage(activeUploadPhaseProgress, activeUploadPhaseDefinitions);

            $progressPercent.text(`${percent}%`);
            $progress.css('width', `${percent}%`);
            $status.attr('title', phaseProgressSummary(activeUploadPhaseProgress, activeUploadPhaseDefinitions));
            if (label) {
                $status.text(label);
            }

            return true;
        };
        const updateUploadPhaseProgress = (updates = {}, label = '') => {
            if (!activeUploadPhaseProgress || !activeUploadPhaseDefinitions.length) {
                beginUploadPhaseProgress(label || 'Processing');
            }

            activeUploadPhaseProgress = {
                ...activeUploadPhaseProgress,
                ...Object.fromEntries(Object.entries(updates).map(([key, value]) => [key, clampProgressPercent(value)])),
            };

            renderUploadPhaseProgress(label);
        };
        const markUploadPrepareProgress = (completed, total) => {
            const percent = total > 0 ? Math.round((Math.max(0, completed) / total) * 100) : 100;

            updateUploadPhaseProgress({
                prepare: percent,
                silero: percent,
            }, `Preparing audio ${Math.max(0, completed)} of ${Math.max(0, total)}`);
        };
        const markUploadSherpaProgress = (percent, label = '') => {
            updateUploadPhaseProgress({ sherpa: percent }, label);
        };

        const resetSherpaProgress = () => {
            sherpaProgressActive = false;
            $sherpaProgress.addClass('hidden');
            $sherpaStatus.text('Waiting');
            $sherpaPercent.text('0%');
            $sherpaBar.css('width', '0%');
        };

        const renderSherpaProgress = (done, total, options = {}) => {
            const safeTotal = Math.max(0, Number(total || 0));
            const safeDone = Math.min(safeTotal, Math.max(0, Number(done || 0)));
            const rawPercent = safeTotal > 0 ? Math.round((safeDone / safeTotal) * 100) : 0;
            const percent = phaseProgressAverage({ sherpa: rawPercent }, uploadSherpaPhaseDefinitions);
            const failed = Math.max(0, Number(options.failed || 0));
            const status = options.status || (safeDone >= safeTotal ? 'Complete' : 'Diarization in progress');

            sherpaProgressActive = safeTotal > 0 && percent < 100;
            $sherpaProgress.toggleClass('hidden', safeTotal === 0);
            $sherpaStatus.text(failed > 0
                ? `${status} ${safeDone} / ${safeTotal}, ${failed} failed`
                : `${status} ${safeDone} / ${safeTotal}`);
            $sherpaPercent.text(`${percent}%`);
            $sherpaBar.css('width', `${percent}%`);
        };

        const resetUploadProgress = (status = 'Ready', options = {}) => {
            const { keepCancelFlag = false, keepSherpaProgress = false } = options;
            const displayStatus = status === 'Ready' && (cancelRequested || uploadCancelling)
                ? 'Cancelled'
                : status;

            stopSectionProgress();
            preparedSections = [];
            currentSessionId = '';
            currentCategoryName = '';
            pauseRequested = false;
            uploadInFlight = false;
            uploadCancelling = false;
            activePrepareXhrs.forEach((xhr) => xhr?.abort?.());
            activePrepareXhrs = [];

            if (!keepCancelFlag) {
                cancelRequested = false;
            }

            clearUploadState();
            if (!keepSherpaProgress) {
                activeDiarizationMonitorGeneration += 1;
            }
            if (!keepSherpaProgress) {
                resetSherpaProgress();
            }
            resetUploadPhaseProgress();
            if (displayStatus === 'Complete') {
                $progressPercent.text('100%');
                $progress.css('width', '100%');
            }
            $status.text(displayStatus);
            renderQueue();
            syncUploadControls();
        };

        const syncUploadControls = () => {
            const hasSession = Boolean(currentSessionId);

            $queueButton.prop('disabled', uploadInFlight || uploadCancelling || !selectedFile || hasSession);
            $pauseButton.prop('disabled', uploadCancelling || !uploadInFlight || !hasSession || pauseRequested);
            $continueButton.prop('disabled', uploadInFlight || uploadCancelling || !hasSession || !hasUnfinishedSections());
            $retryButton.prop('disabled', uploadInFlight || uploadCancelling || !hasSession || !hasRetryableSections());
            $cancelButton.prop('disabled', uploadCancelling || (!uploadInFlight && !hasUploadProgress()));
            $categoryInput.prop('disabled', uploadInFlight && !uploadCancelling);
            $languageInput.prop('disabled', uploadInFlight && !uploadCancelling);
        };

        const showUploadCancellingState = () => {
            uploadCancelling = true;
            uploadInFlight = false;
            $status.text('Cancelling');
            $status.attr('title', 'Cancelling active upload work and clearing pending sections.');
            preparedSections = preparedSections.map((section) => section.status === 'Complete'
                ? section
                : {
                    ...section,
                    status: 'Cancelled',
                    preparedMeta: 'Cancelling',
                });
            renderQueue();
            syncUploadControls();
        };

        const renderEmptyQueue = (message = 'No pending recordings yet.') => {
            $queueList.html(`
                <div data-upload-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4">
                    <p class="text-sm text-slate-200">${message}</p>
                </div>
            `);
        };

        const normalizeUploadStoredItem = (item) => ({
            ...item,
            id: item.id,
            rangeLabel: item.rangeLabel || item.range_label || '',
            categoryName: item.categoryName || item.category_name || '',
            playUrl: item.play_url || item.playUrl || '',
            deleteUrl: item.delete_url || item.deleteUrl || '',
            translatedText: item.translatedText || item.translated_text || '',
            clipStartMs: Number(item.clipStartMs || item.clip_start_ms || 0),
            clipEndMs: Number(item.clipEndMs || item.clip_end_ms || 0),
            sourceType: item.sourceType || item.source_type || 'upload',
        });

        const getUploadStoredItemsForCategory = () => {
            const selectedCategory = getUploadCategory().toLowerCase();

            if (!selectedCategory) {
                return [];
            }

            return uploadStoredItems
                .filter((item) => String(item.categoryName || '').toLowerCase() === selectedCategory)
                .filter(hasUsefulUploadTranscript)
                .sort((first, second) => {
                    const firstStart = Number(first.clipStartMs || 0);
                    const secondStart = Number(second.clipStartMs || 0);

                    return firstStart === secondStart
                        ? Number(first.id || 0) - Number(second.id || 0)
                        : firstStart - secondStart;
                });
        };

        const rememberStoredUploadItem = (item) => {
            const normalized = normalizeUploadStoredItem(item);

            if (!normalized.id) {
                return;
            }

            uploadStoredItems = [
                ...uploadStoredItems.filter((entry) => String(entry.id) !== String(normalized.id)),
                normalized,
            ];
        };

        const showQueuedDiarizationProgress = (queuedIds) => {
            const uniqueIds = [...new Set(queuedIds.map((id) => Number(id)).filter((id) => id > 0))];

            if (!uniqueIds.length) {
                return [];
            }

            renderSherpaProgress(0, uniqueIds.length, { status: 'Diarization in progress' });

            return uniqueIds;
        };

        const monitorQueuedDiarization = (audioChunkIds, attemptsRemaining = 450, generation = activeDiarizationMonitorGeneration) => {
            if (generation !== activeDiarizationMonitorGeneration) {
                return;
            }

            const pendingIds = [...new Set(audioChunkIds.map((id) => Number(id)).filter((id) => id > 0))];

            if (!storedUrl || !pendingIds.length || attemptsRemaining <= 0) {
                if (pendingIds.length && attemptsRemaining <= 0) {
                    renderSherpaProgress(0, pendingIds.length, { status: 'Timed out' });
                }
                return;
            }

            if ($sherpaProgress.hasClass('hidden')) {
                renderSherpaProgress(0, pendingIds.length, { status: 'Diarization in progress' });
            }

            window.setTimeout(() => {
                if (generation !== activeDiarizationMonitorGeneration) {
                    return;
                }

                $.getJSON(storedUrl)
                    .done((response) => {
                        if (generation !== activeDiarizationMonitorGeneration) {
                            return;
                        }

                        const items = Array.isArray(response?.data) ? response.data : [];
                        const rowsById = new Map(items.map((item) => [Number(item?.id || 0), item]));
                        const stillPending = [];
                        let failed = 0;

                        pendingIds.forEach((id) => {
                            const row = rowsById.get(id);
                            const status = String(row?.status || '');

                            if (!row || ['diarization_queued', 'diarization_processing', 'diarization_retrying', 'diarization_waiting_transcript'].includes(status)) {
                                stillPending.push(id);
                                return;
                            }

                            if (status === 'diarization_failed') {
                                failed += 1;
                            }
                            rememberStoredUploadItem(row);
                        });

                        renderSherpaProgress(
                            pendingIds.length - stillPending.length,
                            pendingIds.length,
                            { failed, status: stillPending.length ? 'Diarization in progress' : 'Complete' },
                        );

                        if (stillPending.length) {
                            monitorQueuedDiarization(pendingIds, attemptsRemaining - 1, generation);
                        } else {
                            renderTranscript();
                        }
                    })
                    .fail(() => {
                        if (generation !== activeDiarizationMonitorGeneration) {
                            return;
                        }

                        renderSherpaProgress(
                            0,
                            pendingIds.length,
                            { status: 'Diarization in progress' },
                        );
                        monitorQueuedDiarization(pendingIds, attemptsRemaining - 1, generation);
                    });
            }, 2000);
        };

        const restartQueuedDiarizationMonitor = (queuedIds) => {
            const uniqueIds = showQueuedDiarizationProgress(queuedIds);

            if (!uniqueIds.length || !storedUrl) {
                return;
            }

            activeDiarizationMonitorGeneration += 1;
            monitorQueuedDiarization(uniqueIds, 450, activeDiarizationMonitorGeneration);
        };

        const syncUploadCategoriesFromStoredItems = () => {
            uploadCategories = [...new Set(uploadStoredItems
                .map((item) => String(item.categoryName || '').trim())
                .filter(Boolean))]
                .sort((first, second) => first.localeCompare(second));
        };

        const setUploadStoredItemPlaybackState = (itemId, playing) => {
            const $row = $transcriptList.find(`[data-upload-stored-item="${itemId}"]`);
            if (!$row.length) {
                return;
            }

            $row.find('[data-upload-stored-play-icon="play"]').toggleClass('hidden', playing);
            $row.find('[data-upload-stored-play-icon="pause"]').toggleClass('hidden', !playing);
            $row.find('[data-upload-stored-play-label]').text(playing ? 'Pause' : 'Play');
            $row.toggleClass('border-cyan-300/20 bg-cyan-300/5', playing);
        };

        const stopActiveUploadAudio = () => {
            if (!activeUploadAudio) {
                return;
            }

            const itemId = activeUploadAudioId;
            activeUploadAudio.pause();
            activeUploadAudio.currentTime = 0;

            if (itemId) {
                setUploadStoredItemPlaybackState(itemId, false);
            }

            activeUploadAudio = null;
            activeUploadAudioId = null;
        };

        const playUploadStoredItem = (item) => {
            if (activeUploadAudioId === item.id && activeUploadAudio) {
                if (activeUploadAudio.paused) {
                    activeUploadAudio.play();
                    setUploadStoredItemPlaybackState(item.id, true);
                } else {
                    activeUploadAudio.pause();
                    setUploadStoredItemPlaybackState(item.id, false);
                }

                return;
            }

            stopActiveUploadAudio();

            activeUploadAudio = new Audio(item.playUrl);
            activeUploadAudio.preload = 'metadata';
            activeUploadAudioId = item.id;

            activeUploadAudio.addEventListener('ended', () => {
                setUploadStoredItemPlaybackState(item.id, false);
                activeUploadAudio = null;
                activeUploadAudioId = null;
            });
            activeUploadAudio.addEventListener('pause', () => {
                if (activeUploadAudioId === item.id && activeUploadAudio?.paused) {
                    setUploadStoredItemPlaybackState(item.id, false);
                }
            });

            activeUploadAudio.play();
            setUploadStoredItemPlaybackState(item.id, true);
        };

        const deleteUploadStoredItem = (item) => {
            if (!item.deleteUrl) {
                notifyError('Could not remove this clip right now.');
                return;
            }

            if (activeUploadAudioId === item.id) {
                stopActiveUploadAudio();
            }

            $.ajax({
                url: item.deleteUrl,
                method: 'DELETE',
                success: () => {
                    uploadStoredItems = uploadStoredItems.filter((entry) => String(entry.id) !== String(item.id));
                    cleanedSections = cleanedSections.filter((section) => String(section.audioChunkId) !== String(item.id));
                    syncUploadCategoriesFromStoredItems();
                    renderTranscript();
                    updateCleanerProgress();
                    refreshUploadCategorySuggestions();
                },
                error: () => {
                    notifyError('Could not remove this clip right now.');
                },
            });
        };

        const getCleanerBatchCount = () => {
            return getCleanerBatches().length;
        };

        const getCleanerBatches = () => {
            const batches = new Map();
            const polishWindowMs = 5 * 60 * 1000;

            getUploadStoredItemsForCategory().forEach((section) => {
                const startMs = Math.max(0, Number(section.clipStartMs || section.clip_start_ms || 0));
                const windowIndex = Math.floor(startMs / polishWindowMs);

                if (!batches.has(windowIndex)) {
                    batches.set(windowIndex, {
                        windowIndex,
                        startMs: windowIndex * polishWindowMs,
                    });
                }
            });

            return Array.from(batches.values()).sort((first, second) => first.windowIndex - second.windowIndex);
        };

        const updateCleanerProgress = () => {
            const total = getCleanerBatchCount();
            const cleanedCount = cleanedSections.length;
            const rawCount = getUploadStoredItemsForCategory().length;
            const hasCleanedCategory = hasCleanedUploadTranscriptForCategory(getUploadCategory());
            const isComplete = total > 0 && hasCleanedCategory && cleanedCount >= rawCount;
            const done = isComplete ? total : 0;
            const activeDone = cleanerStatus === 'Polishing' ? cleanerCompletedBatches : done;
            const percent = total > 0 ? Math.min(100, Math.round((activeDone / total) * 100)) : 0;

            if ((total === 0 || !hasCleanedCategory) && cleanerStatus !== 'Polishing') {
                cleanerStatus = 'Waiting';
            }

            if (isComplete && cleanerStatus !== 'Polishing') {
                cleanerStatus = 'Complete';
            }

            $cleanerState.text(cleanerStatus);
            $cleanerProgressLabel.text(`${cleanerStatus === 'Polishing' ? cleanerCompletedBatches : activeDone} / ${total} batches`);
            $cleanerProgressPercent.text(`${percent}%`);
            $cleanerProgressBar.css('width', `${percent}%`);

            if (cleanerStatus === 'Polishing') {
                $cleanerProgressNote.text(`Polishing ${total} five-minute ${total === 1 ? 'batch' : 'batches'}.`);
                return;
            }

            if (cleanerStatus === 'Complete') {
                $cleanerProgressNote.text(`${cleanedCount} cleaned ${cleanedCount === 1 ? 'section' : 'sections'} ready for export.`);
                return;
            }

            if (cleanerStatus === 'Failed') {
                $cleanerProgressNote.text('Polishing failed. You can polish the transcript again.');
                return;
            }

            $cleanerProgressNote.text(total > 0
                ? `${total} five-minute ${total === 1 ? 'batch is' : 'batches are'} ready to polish.`
                : 'The polished transcript will be prepared in five-minute batches after raw transcription is ready.');
        };

        const renderSectionProgress = () => {
            const total = preparedSections.length;
            const complete = preparedSections.filter((section) => section.status === 'Complete').length;
            const nextIndex = preparedSections.findIndex((section) => section.status !== 'Complete');
            const position = activeSectionPosition || (nextIndex >= 0 ? nextIndex + 1 : total);
            const percent = total === 0
                ? 0
                : activeSectionPosition > 0
                    ? activeSectionProgress
                    : complete === total
                        ? 100
                        : 0;
            const activeUnits = activeBatchPositions.length > 0
                ? activeBatchPositions.length
                : (activeSectionPosition > 0 ? 1 : 0);
            const transcribePercent = total === 0
                ? 0
                : Math.round(((complete + (activeUnits * (percent / 100))) / total) * 100);
            const progressIndex = activeBatchPositions.length > 0
                ? Math.min(
                    activeBatchPositions.length - 1,
                    Math.floor((Math.max(0, percent) / 100) * activeBatchPositions.length),
                )
                : -1;
            const activeLabel = progressIndex >= 0
                ? `Processing ${activeBatchPositions[progressIndex]} of ${total}`
                : '';

            if (activeUploadPhaseProgress) {
                activeUploadPhaseProgress = {
                    ...activeUploadPhaseProgress,
                    transcribe: clampProgressPercent(transcribePercent),
                };
                renderUploadPhaseProgress(activeLabel);
                return;
            }

            $progressPercent.text(`${percent}%`);
            $progress.css('width', `${percent}%`);

            if (activeBatchPositions.length > 0) {
                $status.text(activeLabel);
            }
        };

        const stopSectionProgress = () => {
            if (activeSectionProgressTimer) {
                window.clearInterval(activeSectionProgressTimer);
                activeSectionProgressTimer = null;
            }

            clearWhisperProgress(activeSectionProgressId);
            activeSectionProgressId = '';

            activeSectionPosition = 0;
            activeSectionProgress = 0;
            activeBatchPositions = [];
        };

        const beginSectionProgress = (position, progressId = '', offline = false) => {
            stopSectionProgress();
            activeSectionPosition = position;
            activeSectionProgress = 0;
            activeSectionProgressId = progressId;
            renderSectionProgress();

            if (offline && progressId) {
                $status.text('Preparing offline audio');
                registerWhisperProgress(progressId, (whisperPercent) => {
                    if (activeSectionProgressTimer) {
                        window.clearInterval(activeSectionProgressTimer);
                        activeSectionProgressTimer = null;
                    }

                    activeSectionProgress = Math.round(whisperPercent);
                    $status.text(whisperPercent >= 100
                        ? 'Separating speakers'
                        : `Whisper ${Math.round(whisperPercent)}%`);
                    renderSectionProgress();
                });
                activeSectionProgressTimer = window.setInterval(() => {
                    activeSectionProgress = Math.min(8, activeSectionProgress + 1);
                    renderSectionProgress();
                }, 500);
                return;
            }

            activeSectionProgressTimer = window.setInterval(() => {
                const remaining = 98 - activeSectionProgress;
                activeSectionProgress = Math.min(98, activeSectionProgress + Math.max(1, Math.ceil(remaining * 0.08)));
                renderSectionProgress();
            }, 350);
        };

        const completeSectionProgress = async () => {
            if (activeSectionProgressTimer) {
                window.clearInterval(activeSectionProgressTimer);
                activeSectionProgressTimer = null;
            }

            clearWhisperProgress(activeSectionProgressId);
            activeSectionProgressId = '';

            activeSectionProgress = 100;
            renderSectionProgress();
            await pause(150);
            activeSectionPosition = 0;
            activeSectionProgress = 0;
        };

        const updateProgress = () => {
            const visible = preparedSections.filter((section) => section.status !== 'Complete').length;

            $activeCount.text(String(visible));
            renderSectionProgress();
            updateCleanerProgress();
            syncUploadControls();
            saveUploadState();
        };

        const renderTranscript = () => {
            const selectedCategory = getUploadCategory();
            const useCleaned = $exportMode.val() === 'clean';
            const rawItems = getUploadStoredItemsForCategory();
            const completed = useCleaned
                ? (hasCleanedUploadTranscriptForCategory(selectedCategory)
                    ? cleanedSections.filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || ''))
                    : [])
                : rawItems;

            $transcriptBadge.text(String(rawItems.length));

            if (!completed.length) {
                $transcriptList.html(`
                    <div data-upload-transcript-empty class="w-full py-4">
                        <p class="text-sm text-slate-200">${!selectedCategory ? 'Choose a project name.' : (useCleaned && rawItems.length ? 'Polish the transcript before viewing cleaned text.' : 'No entries yet.')}</p>
                    </div>
                `);
                return;
            }

            $transcriptList.html(completed.map((section) => {
                const itemId = section.id || section.audioChunkId || section.audio_chunk_id || '';
                const playableItem = rawItems.find((item) => String(item.id) === String(itemId));
                const translatedText = useCleaned
                    ? (section.cleanText || section.clean_text || '')
                    : (section.translatedText || section.translated_text || section.text || '');
                const transcriptTimestamps = useCleaned
                    ? (section.cleanTimestamps || section.clean_timestamps || [])
                    : (section.timestamps || section.transcription_timestamps || []);

                return `
                <article data-upload-stored-item="${itemId}" class="w-full border-b border-white/8 py-2.5 last:border-b-0">
                    <div class="flex w-full flex-col gap-2.5 md:flex-row md:items-start md:gap-4">
                        <div class="flex shrink-0 items-start gap-2 md:w-[12.5rem]">
                            <p class="max-w-full text-xs font-medium leading-5 tracking-[0.14em] text-cyan-300">${section.rangeLabel || section.range_label || ''}</p>
                            ${playableItem ? `
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        data-upload-stored-action="play"
                                        class="group inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
                                    >
                                        <span data-upload-stored-play-icon="play" class="text-emerald-300">
                                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                                <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                            </svg>
                                        </span>
                                        <span data-upload-stored-play-icon="pause" class="hidden text-rose-400">
                                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                                <rect x="6.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                                <rect x="13.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                            </svg>
                                        </span>
                                        <span class="sr-only" data-upload-stored-play-label>Play</span>
                                    </button>
                                    <button
                                        type="button"
                                        data-upload-stored-action="remove"
                                        class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg border border-rose-400/20 bg-rose-400/10 text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
                                    >
                                        <span class="sr-only">Delete</span>
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                            <path d="M9 3.5h6a1.5 1.5 0 0 1 1.5 1.5V6h2.5a1 1 0 1 1 0 2h-.58l-.78 10.01A2.5 2.5 0 0 1 15.17 20H8.83a2.5 2.5 0 0 1-2.49-1.99L5.56 8H5a1 1 0 1 1 0-2h2.5V5A1.5 1.5 0 0 1 9 3.5Zm1 2V6h4V5.5h-4ZM7.58 8l.77 9.83c.04.45.42.79.87.79h6.56c.45 0 .83-.34.87-.79L17.42 8H7.58Z" />
                                        </svg>
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                        <div class="min-w-0 flex-1">
                            ${renderTranscriptText(translatedText, transcriptTimestamps)}
                        </div>
                    </div>
                </article>
                `;
            }).join(''));

            if (activeUploadAudioId) {
                setUploadStoredItemPlaybackState(activeUploadAudioId, true);
            }
        };

        const exportTranscript = () => {
            const selectedCategory = getUploadCategory();
            const useCleaned = $exportMode.val() === 'clean';
            const completed = useCleaned
                ? (hasCleanedUploadTranscriptForCategory(selectedCategory)
                    ? cleanedSections.filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || ''))
                    : [])
                : getUploadStoredItemsForCategory();

            if (!completed.length) {
                notifyError(useCleaned
                    ? 'Polish the transcript before exporting the cleaned version.'
                    : 'No transcription is ready to export yet.');
                return;
            }

            const content = completed
                .filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || section.translatedText || section.translated_text || section.text || ''))
                .map((section) => `${section.rangeLabel || section.range_label || ''}\n${section.cleanText || section.clean_text || section.translatedText || section.translated_text || section.text || ''}`)
                .join('\n\n');

            downloadTextFile(`${slugify(selectedCategory || selectedFile?.name)}-${useCleaned ? 'cleaned' : 'raw'}-transcription.txt`, content);
        };

        const renderVadLogs = (logs, categoryName) => {
            uploadShowingVadLogs = true;
            $transcriptBadge.text(String(logs.length));

            if (!logs.length) {
                $transcriptList.html(`
                    <div class="w-full py-4">
                        <p class="text-sm text-slate-200">No processing logs found for ${escapeHtml(categoryName)}.</p>
                    </div>
                `);
                return;
            }

            $transcriptList.html(logs.map((log) => {
                const segments = Array.isArray(log.speech_segments) ? log.speech_segments : [];
                const statusClasses = log.speech_detected
                    ? 'border-emerald-300/20 bg-emerald-300/10 text-emerald-100'
                    : 'border-amber-300/20 bg-amber-300/10 text-amber-100';
                const statusLabel = log.speech_detected ? 'Speech' : 'No speech';
                const segmentHtml = segments.length
                    ? segments.map((segment, index) => `
                        <div class="rounded-lg border border-white/10 bg-white/[0.03] px-2.5 py-2">
                            <p class="text-[0.66rem] font-semibold uppercase tracking-[0.18em] text-cyan-300">Segment ${index + 1}</p>
                            <p class="mt-1 text-xs text-white">${formatClock(Number(segment.absolute_start_ms || 0))}-${formatClock(Number(segment.absolute_end_ms || 0))}</p>
                            <p class="mt-0.5 text-[0.68rem] text-slate-400">Inside clip ${formatRelativeClock(Number(segment.start_ms || 0))}-${formatRelativeClock(Number(segment.end_ms || 0))}</p>
                        </div>
                    `).join('')
                    : '<p class="text-xs text-slate-400">Hosted transcription skipped for this range.</p>';

                return `
                    <article class="w-full border-b border-white/8 py-2.5 last:border-b-0">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-xs font-medium leading-5 tracking-[0.14em] text-cyan-300">${escapeHtml(log.range_label || '')}</p>
                                <p class="mt-0.5 text-[0.68rem] text-slate-400">Clip ${Number(log.clip_index || 0)} · ${formatClock(Number(log.clip_start_ms || 0))}-${formatClock(Number(log.clip_end_ms || 0))}</p>
                            </div>
                            <span class="rounded-lg border px-2 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.16em] ${statusClasses}">${statusLabel}</span>
                        </div>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            ${segmentHtml}
                        </div>
                        <p class="mt-2 text-[0.68rem] text-slate-500">Speech ${formatClock(Number(log.speech_duration_ms || 0))} · Filtered ${formatBytes(Number(log.filtered_size_bytes || 0))}</p>
                    </article>
                `;
            }).join(''));
        };

        const setUploadLogButtonLabel = () => {
            $logButton.prop('disabled', false).html(uploadShowingVadLogs ? `
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" />
                </svg>
                Transcript
            ` : `
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M8 6h13" />
                    <path d="M8 12h13" />
                    <path d="M8 18h13" />
                    <path d="M3 6h.01" />
                    <path d="M3 12h.01" />
                    <path d="M3 18h.01" />
                </svg>
                Log
            `);
        };

        const loadVadLogs = () => {
            if (uploadShowingVadLogs) {
                uploadShowingVadLogs = false;
                setUploadLogButtonLabel();
                renderTranscript();
                return;
            }

            const categoryName = getUploadCategory();

            if (!categoryName) {
                notifyError('Choose a project name before loading processing logs.');
                return;
            }

            if (!vadLogUrl) {
                notifyError('Processing logs are unavailable.');
                return;
            }

            $logButton.prop('disabled', true).text('Loading');

            $.getJSON(vadLogUrl, {
                category_name: categoryName,
                source_type: 'upload',
            })
                .done((response) => {
                    renderVadLogs(Array.isArray(response?.data) ? response.data : [], categoryName);
                    setUploadLogButtonLabel();
                })
                .fail((xhr) => {
                    notifyError(String(xhr?.responseJSON?.message || 'Could not load processing logs.'));
                })
                .always(() => {
                    setUploadLogButtonLabel();
                });
        };

        const mergeCleanedRows = (rows) => {
            const existing = new Map(cleanedSections.map((section) => [String(section.audioChunkId), section]));

            rows.forEach((row) => {
                existing.set(String(row.audio_chunk_id), {
                    audioChunkId: row.audio_chunk_id,
                    rangeLabel: row.range_label || '',
                    cleanText: row.clean_text || '',
                    cleanTimestamps: row.clean_timestamps || [],
                });
            });

            cleanedSections = Array.from(existing.values())
                .sort((first, second) => Number(first.audioChunkId || 0) - Number(second.audioChunkId || 0));
        };

        const furnishTranscript = async () => {
            const categoryName = getUploadCategory();

            if (!categoryName) {
                notifyError('Choose a project name before polishing the transcript.');
                return;
            }

            if (!getUploadStoredItemsForCategory().length) {
                notifyError('No raw transcript is ready to polish yet.');
                return;
            }

            if (furnishInFlight || !furnishUrl) {
                return;
            }

            const instructions = await requestPolishInstructions();

            if (!instructions) {
                return;
            }

            furnishInFlight = true;
            cleanerStatus = 'Polishing';
            cleanerCompletedBatches = 0;
            uploadCleanedCategoryName = categoryName;
            $furnishButton.prop('disabled', true).text('Polishing');
            renderTranscript();
            updateCleanerProgress();

            try {
                const batches = getCleanerBatches();
                const total = batches.length;

                for (let batchIndex = 0; batchIndex < batches.length; batchIndex += 1) {
                    const batch = batches[batchIndex];
                    const response = await $.ajax({
                        url: furnishUrl,
                        method: 'POST',
                        data: {
                            user_id: defaultUserId,
                            category_name: categoryName,
                            window_index: batch.windowIndex,
                            instructions,
                        },
                    });
                    const rows = Array.isArray(response?.data) ? response.data : [];

                    mergeCleanedRows(rows);
                    cleanerCompletedBatches = batchIndex + 1;
                    updateCleanerProgress();
                    renderTranscript();

                    if (batchIndex < total - 1) {
                        await pause(4000);
                    }
                }

                cleanerStatus = 'Complete';
                updateCleanerProgress();
                renderTranscript();
                notify('Transcript polished.');
            } catch (xhr) {
                cleanerStatus = 'Failed';
                updateCleanerProgress();
                notifyError(String(xhr?.responseJSON?.message || 'Transcript could not be polished.'));
            } finally {
                furnishInFlight = false;
                $furnishButton.prop('disabled', false).text('Polish');
            }
        };

        const renderQueue = () => {
            const visibleSections = preparedSections.filter((section) => section.status !== 'Complete');

            if (!visibleSections.length) {
                const message = preparedSections.some((section) => section.status === 'Complete')
                    ? 'No pending recordings yet.'
                    : selectedFile ? 'No pending recordings yet.' : undefined;
                renderEmptyQueue(message);
                updateProgress();
                renderTranscript();
                return;
            }

            $queueList.html(visibleSections.map((section) => `
                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.3em] text-cyan-300">Clip ${section.index}</p>
                            <p class="mt-2 text-lg font-semibold text-white">${section.rangeLabel}</p>
                            <p class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-500">${section.preparedMeta || ''}</p>
                        </div>
                        <span class="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.24em] text-slate-300">
                            ${section.status}
                        </span>
                    </div>
                </article>
            `).join(''));

            updateProgress();
            renderTranscript();
        };

        const buildSections = () => {
            if (!selectedFile) {
                return [];
            }

            const chunkLengthMs = getChunkLengthMs();
            const durationMs = selectedDurationMs > 0 ? selectedDurationMs : chunkLengthMs;
            const count = Math.max(1, Math.ceil(durationMs / chunkLengthMs));

            return Array.from({ length: count }, (_, index) => {
                const startMs = index * chunkLengthMs;
                const endMs = Math.min((index + 1) * chunkLengthMs, durationMs);

                return {
                    index: index + 1,
                    startMs,
                    endMs,
                    rangeLabel: `${formatClock(startMs)}-${formatClock(endMs)}`,
                    status: 'Waiting',
                    text: '',
                };
            });
        };

        const syncPlan = () => {
            const durationMs = selectedDurationMs > 0 ? selectedDurationMs : 0;

            $duration.text(durationMs > 0 ? formatClock(durationMs) : '--:--');
            $status.text(selectedFile ? 'Ready' : 'Ready');
            $queueButton.prop('disabled', uploadInFlight || !selectedFile);
            syncUploadControls();
        };

        const syncUploadCategoryOptions = () => [...new Set(uploadCategories.filter(Boolean))];

        const renderUploadCategorySuggestions = () => {
            const categories = syncUploadCategoryOptions();
            const currentValue = getUploadCategory().toLowerCase();
            const filtered = currentValue
                ? categories.filter((category) => category.toLowerCase().includes(currentValue))
                : categories;

            if (!filtered.length) {
                $categorySuggestions.html('<div class="px-3 py-2 text-sm text-slate-500">No project names yet.</div>');
                return;
            }

            $categorySuggestions.html(filtered
                .map((category) => `
                    <button
                        type="button"
                        data-upload-category-pick="${escapeHtml(category)}"
                        class="flex w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm text-white transition hover:bg-white/8"
                    >
                        ${escapeHtml(category)}
                    </button>
                `)
                .join(''));
        };

        const openUploadCategorySuggestions = () => {
            renderUploadCategorySuggestions();
            $categorySuggestions.removeClass('hidden');
        };

        const closeUploadCategorySuggestions = () => {
            $categorySuggestions.addClass('hidden');
        };

        const refreshUploadCategorySuggestions = () => {
            if (!$categorySuggestions.hasClass('hidden')) {
                renderUploadCategorySuggestions();
            }
        };

        const rememberUploadCategory = (categoryName) => {
            const category = String(categoryName || '').trim();

            if (!category || uploadCategories.some((item) => item.toLowerCase() === category.toLowerCase())) {
                return;
            }

            uploadCategories = [...uploadCategories, category].sort((first, second) => first.localeCompare(second));
            refreshUploadCategorySuggestions();
        };

        const loadUploadCategories = () => {
            if (!storedUrl) {
                uploadCategories = [];
                uploadStoredItems = [];
                renderTranscript();
                return;
            }

            $.getJSON(storedUrl)
                .done((response) => {
                    const items = Array.isArray(response?.data) ? response.data : [];
                    uploadStoredItems = items
                        .map(normalizeUploadStoredItem)
                        .filter((item) => item.sourceType === 'upload')
                        .filter(hasUsefulUploadTranscript);
                    syncUploadCategoriesFromStoredItems();
                    renderTranscript();
                    refreshUploadCategorySuggestions();
                })
                .fail(() => {
                    uploadCategories = [];
                    uploadStoredItems = [];
                    renderTranscript();
                    refreshUploadCategorySuggestions();
                });
        };

        const setProcessingState = (isProcessing) => {
            uploadInFlight = isProcessing;
            if (isProcessing) {
                uploadCancelling = false;
            }
            if (isProcessing) {
                resetSherpaProgress();
            }
            $status.text(isProcessing ? 'Processing' : selectedFile ? 'Ready' : 'Ready');
            syncUploadControls();
        };

        const buildUploadErrorMessage = (xhr) => {
            const serverMessage = String(xhr?.responseJSON?.message || '').trim();
            const fieldErrors = xhr?.responseJSON?.errors || {};

            if (fieldErrors.category_name?.length) {
                return 'Choose a project name before processing the upload.';
            }

            if (fieldErrors.audio_file?.length) {
                return 'Choose a valid audio file before processing.';
            }

            if (serverMessage) {
                return serverMessage;
            }

            return 'Audio upload could not be processed.';
        };

        const buildUploadSectionFormData = (sessionId, categoryName, section, options = {}) => {
            const formData = new FormData();
            formData.append('upload_session_id', sessionId);
            formData.append('user_id', String(defaultUserId));
            formData.append('category_name', categoryName);
            formData.append('clip_index', String(section.index));
            formData.append('clip_start_ms', String(section.startMs));
            formData.append('clip_end_ms', String(section.endMs));
            formData.append('duration_ms', String(section.durationMs || Math.max(1, section.endMs - section.startMs)));
            formData.append('range_label', section.rangeLabel);

            Object.entries(options).forEach(([key, value]) => {
                if (value !== undefined && value !== null && value !== '') {
                    formData.append(key, String(value));
                }
            });

            return formData;
        };

        const prepareUploadSections = async (sessionId, categoryName) => {
            const prepareUrl = uploadPrepareBatchUrl || uploadPrepareUrl;

            if (!prepareUrl) {
                return true;
            }

            const candidates = preparedSections
                .map((section, index) => ({ section, index }))
                .filter(({ section }) => !section.prepared && section.status !== 'Complete');
            const total = candidates.length;

            if (!total) {
                return true;
            }

            let cursor = 0;
            let completed = 0;
            let failed = false;
            const concurrency = getUploadPrepareConcurrency();
            beginUploadPhaseProgress(`Preparing audio 0 of ${total}`);
            markUploadPrepareProgress(0, total);

            if (uploadPrepareBatchUrl) {
                candidates.forEach(({ index }) => {
                    preparedSections[index] = {
                        ...preparedSections[index],
                        status: 'Preparing',
                        preparedMeta: 'Preparing audio',
                    };
                });
                renderQueue();

                try {
                    for (let offset = 0; offset < candidates.length; offset += concurrency) {
                        if (cancelRequested) {
                            return false;
                        }

                        const batch = candidates.slice(offset, offset + concurrency);
                        const xhr = $.ajax({
                            url: uploadPrepareBatchUrl,
                            method: 'POST',
                            data: JSON.stringify({
                                upload_session_id: sessionId,
                                user_id: defaultUserId,
                                category_name: categoryName,
                                concurrency: batch.length,
                                speaker_session_id: getTranscriptionEngine() === 'online' ? sessionId : '',
                                sections: batch.map(({ section }) => ({
                                    clip_index: section.index,
                                    clip_start_ms: section.startMs,
                                    clip_end_ms: section.endMs,
                                    duration_ms: section.durationMs || Math.max(1, section.endMs - section.startMs),
                                    range_label: section.rangeLabel,
                                })),
                            }),
                            contentType: 'application/json',
                        });
                        activePrepareXhrs.push(xhr);

                        try {
                            const response = await xhr;
                            const rows = Array.isArray(response?.data) ? response.data : [];

                            if (rows.length !== batch.length) {
                                throw { responseJSON: { message: 'Audio preparation returned an incomplete batch.' } };
                            }

                            rows.forEach((data, rowIndex) => {
                                const { index } = batch[rowIndex];
                                const skipped = Boolean(data.skipped || data.prepared_skipped);
                                const audioChunkId = Number(data.audio_chunk_id || 0);
                                completed += 1;
                                preparedSections[index] = {
                                    ...preparedSections[index],
                                    prepared: true,
                                    noSpeech: skipped,
                                    sourceName: data.source_name || '',
                                    preparedName: data.prepared_name || '',
                                    preparedSkipped: skipped,
                                    audioChunkId: audioChunkId > 0 ? audioChunkId : null,
                                    status: 'Ready',
                                    preparedMeta: skipped
                                        ? 'No speech detected'
                                        : `${formatBytes(Number(data.prepared_file_size_bytes || 0))} prepared`,
                                };
                            });

                            markUploadPrepareProgress(completed, total);
                            renderQueue();
                        } finally {
                            activePrepareXhrs = activePrepareXhrs.filter((item) => item !== xhr);
                        }
                    }
                    return true;
                } catch (xhr) {
                    if (cancelRequested || xhr?.statusText === 'abort') {
                        return false;
                    }

                    candidates.forEach(({ index }) => {
                        if (preparedSections[index]?.status === 'Preparing') {
                            preparedSections[index] = {
                                ...preparedSections[index],
                                status: 'Failed',
                                preparedMeta: 'Audio preparation failed',
                            };
                        }
                    });
                    $status.text('Failed');
                    renderQueue();
                    notifyError(buildUploadErrorMessage(xhr));
                    return false;
                }
            }

            const runNext = async () => {
                while (!failed && !cancelRequested && cursor < candidates.length) {
                    const { section, index } = candidates[cursor];
                    cursor += 1;

                    preparedSections[index] = {
                        ...preparedSections[index],
                        status: 'Preparing',
                        preparedMeta: 'Preparing audio',
                    };
                    renderQueue();

                    const xhr = $.ajax({
                        url: uploadPrepareUrl,
                        method: 'POST',
                        data: buildUploadSectionFormData(sessionId, categoryName, section, {
                            speaker_session_id: getTranscriptionEngine() === 'online' ? sessionId : '',
                        }),
                        processData: false,
                        contentType: false,
                    });
                    activePrepareXhrs.push(xhr);

                    try {
                        const response = await xhr;
                        const data = response?.data || {};
                        const skipped = Boolean(data.skipped || data.prepared_skipped);
                        const audioChunkId = Number(data.audio_chunk_id || 0);
                        completed += 1;
                        preparedSections[index] = {
                            ...preparedSections[index],
                            prepared: true,
                            noSpeech: skipped,
                            sourceName: data.source_name || '',
                            preparedName: data.prepared_name || '',
                            preparedSkipped: skipped,
                            audioChunkId: audioChunkId > 0 ? audioChunkId : null,
                            status: 'Ready',
                            preparedMeta: skipped
                                ? 'No speech detected'
                                : `${formatBytes(Number(data.prepared_file_size_bytes || 0))} prepared`,
                        };
                        markUploadPrepareProgress(completed, total);
                        renderQueue();
                    } catch (xhr) {
                        if (cancelRequested || xhr?.statusText === 'abort') {
                            failed = true;
                            return;
                        }

                        failed = true;
                        preparedSections[index] = {
                            ...preparedSections[index],
                            status: 'Failed',
                            preparedMeta: 'Audio preparation failed',
                        };
                        $status.text('Failed');
                        renderQueue();
                        notifyError(buildUploadErrorMessage(xhr));
                    } finally {
                        activePrepareXhrs = activePrepareXhrs.filter((item) => item !== xhr);
                    }
                }
            };

            await Promise.all(Array.from({ length: concurrency }, () => runNext()));

            return !failed && !cancelRequested;
        };

        const startPreparedUploadDiarization = async (sessionId) => {
            if (getTranscriptionEngine() !== 'online' || !uploadDiarizeUrl) {
                return [];
            }

            const sections = preparedSections
                .map((section) => ({
                    audio_chunk_id: Number(section.audioChunkId || 0),
                    prepared_name: section.preparedName || '',
                    clip_index: section.index,
                    clip_start_ms: section.startMs,
                    clip_end_ms: section.endMs,
                    duration_ms: section.durationMs || Math.max(1, section.endMs - section.startMs),
                    range_label: section.rangeLabel,
                }))
                .filter((section) => section.prepared_name);

            if (!sections.length) {
                return [];
            }

            const response = await $.ajax({
                url: uploadDiarizeUrl,
                method: 'POST',
                data: JSON.stringify({
                    upload_session_id: sessionId,
                    speaker_session_id: sessionId,
                    user_id: defaultUserId,
                    category_name: getUploadCategory(),
                    sections,
                }),
                contentType: 'application/json',
            });
            const queuedIds = Array.isArray(response?.data?.audio_chunk_ids)
                ? response.data.audio_chunk_ids.map((id) => Number(id)).filter((id) => id > 0)
                : [];
            const queuedRows = Array.isArray(response?.data?.sections) ? response.data.sections : [];

            if (queuedIds.length > 0) {
                const queuedSet = new Set(queuedIds);
                const queuedByClipIndex = new Map(queuedRows
                    .map((row) => [Number(row?.clip_index || 0), Number(row?.audio_chunk_id || 0)])
                    .filter(([, audioChunkId]) => audioChunkId > 0));
                preparedSections = preparedSections.map((section) => {
                    const audioChunkId = queuedByClipIndex.get(Number(section.index)) || Number(section.audioChunkId || 0);

                    return audioChunkId > 0 && queuedSet.has(audioChunkId)
                        ? { ...section, audioChunkId, diarizationQueued: true }
                        : section;
                });
                restartQueuedDiarizationMonitor(queuedIds);
            }

            return queuedIds;
        };

        const processUploadSections = async (sessionId, categoryName) => {
            cancelRequested = false;
            pauseRequested = false;
            const queuedDiarizationIds = preparedSections
                .filter((section) => section.diarizationQueued && Number(section.audioChunkId || 0) > 0)
                .map((section) => Number(section.audioChunkId));

            if (!audioChunkUrl) {
                preparedSections = preparedSections.map((section) => ({
                    ...section,
                    status: 'Failed',
                }));
                $status.text('Failed');
                renderQueue();
                notifyError('Audio section endpoint is missing. Refresh the page and try again.');
                uploadInFlight = false;
                syncUploadControls();
                return;
            }

            if (!activeUploadPhaseProgress) {
                beginUploadPhaseProgress('Processing');
            }
            updateUploadPhaseProgress({
                prepare: 100,
                silero: 100,
            }, 'Processing');

            const commonSectionPayload = (section) => ({
                clip_index: section.index,
                clip_start_ms: section.startMs,
                clip_end_ms: section.endMs,
                duration_ms: section.durationMs || Math.max(1, section.endMs - section.startMs),
                range_label: section.rangeLabel,
                source_name: section.sourceName || '',
                prepared_name: section.preparedName || '',
                prepared_skipped: section.preparedSkipped ? 1 : 0,
                audio_chunk_id: section.audioChunkId || '',
            });

            const completeBatchRows = async (batch, rows) => {
                await completeSectionProgress();

                if (cancelRequested) {
                    activeSectionXhr = null;
                    resetUploadProgress();
                    return false;
                }

                batch.forEach(({ index, section }, rowOffset) => {
                    const row = rows.find((item) => Number(item?.clip_index || 0) === Number(section.index))
                        || rows[rowOffset]
                        || {};

                    rememberStoredUploadItem(row);
                    if (row.status === 'diarization_queued' && Number(row.id || 0) > 0) {
                        queuedDiarizationIds.push(Number(row.id));
                    }
                    preparedSections[index] = {
                        ...section,
                        status: 'Complete',
                        text: row.translated_text || '',
                        timestamps: row.transcription_timestamps || [],
                        preparedMeta: row.prepared_file_size_bytes
                            ? `${formatBytes(Number(row.prepared_file_size_bytes))} sent`
                            : '',
                    };
                });

                restartQueuedDiarizationMonitor(queuedDiarizationIds);
                rememberUploadCategory(categoryName);
                activeSectionXhr = null;
                renderQueue();
                renderTranscript();
                saveUploadState();
                return true;
            };

            if (audioChunkBatchUrl && getTranscriptionEngine() === 'online') {
                let index = 0;

                while (index < preparedSections.length) {
                    if (cancelRequested || pauseRequested) {
                        break;
                    }

                    if (preparedSections[index].status === 'Complete') {
                        index += 1;
                        continue;
                    }

                    const batch = [];
                    let batchDurationMs = 0;

                    while (index < preparedSections.length && batch.length < maxTranscribeBatchClips) {
                        const section = preparedSections[index];

                        if (section.status === 'Complete') {
                            index += 1;
                            continue;
                        }

                        const durationMs = section.durationMs || Math.max(1, section.endMs - section.startMs);

                        if (durationMs > maxTranscribeBatchDurationMs) {
                            section.status = 'Failed';
                            $status.text('Failed');
                            renderQueue();
                            notifyError('Audio is too big.');
                            uploadInFlight = false;
                            syncUploadControls();
                            return;
                        }

                        if (batch.length > 0 && (batchDurationMs + durationMs) > maxTranscribeBatchDurationMs) {
                            break;
                        }

                        batch.push({ index, section });
                        batchDurationMs += durationMs;
                        index += 1;
                    }

                    if (!batch.length) {
                        continue;
                    }

                    batch.forEach(({ index: sectionIndex }) => {
                        preparedSections[sectionIndex].status = 'Processing';
                    });

                    const positions = batch.map(({ index: sectionIndex }) => sectionIndex + 1);
                    const firstPosition = positions[0];
                    const lastPosition = positions[positions.length - 1];
                    $status.text(`Processing ${firstPosition} of ${preparedSections.length}`);

                    beginSectionProgress(firstPosition, '', false);
                    activeBatchPositions = positions;
                    renderSectionProgress();
                    renderQueue();

                    try {
                        activeSectionXhr = $.ajax({
                            url: audioChunkBatchUrl,
                            method: 'POST',
                            data: JSON.stringify({
                                upload_session_id: sessionId,
                                user_id: defaultUserId,
                                category_name: categoryName,
                                language_code: getUploadLanguageCode(),
                                transcription_engine: getTranscriptionEngine(),
                                whisper_model: getWhisperModel(),
                                speaker_session_id: sessionId,
                                finalize_session: lastPosition === preparedSections.length ? 1 : 0,
                                sections: batch.map(({ section }) => commonSectionPayload(section)),
                            }),
                            contentType: 'application/json',
                        });
                        const response = await activeSectionXhr;
                        const rows = Array.isArray(response?.data)
                            ? response.data
                            : (response?.data ? [response.data] : []);

                        if (!await completeBatchRows(batch, rows)) {
                            return;
                        }
                    } catch (xhr) {
                        activeSectionXhr = null;
                        stopSectionProgress();
                        if (cancelRequested || xhr?.statusText === 'abort') {
                            resetUploadProgress();
                            return;
                        }

                        batch.forEach(({ index: sectionIndex }) => {
                            preparedSections[sectionIndex].status = 'Failed';
                        });
                        $status.text('Failed');
                        renderQueue();
                        notifyError(buildUploadErrorMessage(xhr));
                        uploadInFlight = false;
                        syncUploadControls();
                        return;
                    }
                }
            } else {
            for (let index = 0; index < preparedSections.length; index += 1) {
                if (cancelRequested || pauseRequested) {
                    break;
                }

                if (preparedSections[index].status === 'Complete') {
                    continue;
                }

                preparedSections[index].status = 'Processing';
                $status.text(`Processing ${index + 1} of ${preparedSections.length}`);
                const offline = getTranscriptionEngine() === 'offline';
                const progressId = offline ? createTranscriptionProgressId() : '';
                beginSectionProgress(index + 1, progressId, offline && !preparedSections[index].preparedSkipped);
                renderQueue();

                const section = preparedSections[index];
                const formData = buildUploadSectionFormData(sessionId, categoryName, section);
                formData.append('language_code', getUploadLanguageCode());
                formData.append('transcription_engine', getTranscriptionEngine());
                formData.append('whisper_model', getWhisperModel());
                formData.append('speaker_session_id', sessionId);
                formData.append('source_name', section.sourceName || '');
                formData.append('prepared_name', section.preparedName || '');
                formData.append('prepared_skipped', section.preparedSkipped ? '1' : '0');
                formData.append('audio_chunk_id', section.audioChunkId || '');
                if (progressId) {
                    formData.append('progress_id', progressId);
                }
                formData.append('finalize_session', index === preparedSections.length - 1 ? '1' : '0');

                try {
                    activeSectionXhr = $.ajax({
                        url: audioChunkUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                    });
                    const response = await activeSectionXhr;

                    const chunk = response?.data || {};
                    rememberStoredUploadItem(chunk);
                    rememberUploadCategory(categoryName);
                    await completeSectionProgress();

                    if (cancelRequested) {
                        activeSectionXhr = null;
                        resetUploadProgress();
                        return;
                    }

                    preparedSections[index] = {
                        ...section,
                        status: 'Complete',
                        text: chunk.translated_text || '',
                        timestamps: chunk.transcription_timestamps || [],
                        preparedMeta: chunk.prepared_file_size_bytes
                            ? `${formatBytes(Number(chunk.prepared_file_size_bytes))} sent`
                            : '',
                    };
                    if (getTranscriptionEngine() === 'offline') {
                        const completedSections = preparedSections.filter((item) => item.status === 'Complete').length;
                        markUploadSherpaProgress(
                            preparedSections.length > 0
                                ? Math.round((completedSections / preparedSections.length) * 100)
                                : 100,
                            `Processing ${Math.min(index + 1, preparedSections.length)} of ${preparedSections.length}`,
                        );
                    } else if (chunk.status === 'diarization_queued' && Number(chunk.id || 0) > 0) {
                        queuedDiarizationIds.push(Number(chunk.id));
                        restartQueuedDiarizationMonitor(queuedDiarizationIds);
                    }
                    activeSectionXhr = null;
                    renderQueue();
                    renderTranscript();
                } catch (xhr) {
                    activeSectionXhr = null;
                    stopSectionProgress();
                    if (cancelRequested || xhr?.statusText === 'abort') {
                        resetUploadProgress();
                        return;
                    }

                    preparedSections[index].status = 'Failed';
                    $status.text('Failed');
                    renderQueue();
                    notifyError(buildUploadErrorMessage(xhr));
                    uploadInFlight = false;
                    syncUploadControls();
                    return;
                }
            }
            }

            const pausedWithWorkRemaining = pauseRequested && hasUnfinishedSections();
            stopSectionProgress();

            if (cancelRequested) {
                resetUploadProgress();
                return;
            } else if (pausedWithWorkRemaining) {
                $status.text('Paused');
            } else {
                $status.text('Complete');
            }
            uploadInFlight = false;
            syncUploadControls();
            if (!cancelRequested && !pausedWithWorkRemaining) {
                restartQueuedDiarizationMonitor(queuedDiarizationIds);
                rememberUploadCategory(categoryName);
                loadUploadCategories();
                resetUploadProgress('Complete', {
                    keepSherpaProgress: queuedDiarizationIds.length > 0,
                });
                notify('Audio transcription completed.');
            }
        };

        const prepareUploadSession = () => {
            clampUploadChunkSize();
            const formData = new FormData();

            if (selectedFile?.localPath) {
                formData.append('local_path', selectedFile.localPath);
            } else {
                formData.append('audio_file', selectedFile);
            }

            formData.append('chunk_seconds', String(Math.floor(getChunkLengthMs() / 1000)));

            sourceUploadXhr = $.ajax({
                url: uploadUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: () => {
                    const xhr = $.ajaxSettings.xhr();

                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', (event) => {
                            if (!event.lengthComputable) {
                                $status.text('Uploading source');
                                return;
                            }

                            const percent = Math.max(0, Math.min(100, Math.round((event.loaded / event.total) * 100)));
                            $status.text(`Uploading source ${percent}%`);
                        });
                    }

                    return xhr;
                },
            });

            return sourceUploadXhr;
        };

        const selectUploadFile = (file) => {
            selectedFile = file;
            selectedDurationMs = 0;
            preparedSections = [];
            cleanedSections = [];
            cleanerStatus = 'Waiting';
            cleanerCompletedBatches = 0;
            currentSessionId = '';
            currentCategoryName = '';
            cancelRequested = false;
            uploadCancelling = false;
            clearUploadState();
            setProcessingState(false);

            if (!file) {
                $fileName.text('Select an audio file');
                $fileMeta.text('WAV, MP3, M4A, AAC, OGG, FLAC, and other audio files.');
                syncTranscriptCategory();
                syncPlan();
                renderQueue();
                return;
            }

            $fileName.text(file.name);
            $fileMeta.text(file.localPath
                ? `${formatBytes(file.size)} selected from local path`
                : `${formatBytes(file.size)} selected`);
            syncTranscriptCategory();

            if (file.localPath) {
                syncPlan();
            } else {
                loadMetadata(file);
                syncPlan();
            }

            renderQueue();
        };

        const chooseLocalUploadFile = async () => {
            const invoke = tauriInvoke();

            if (typeof invoke !== 'function') {
                return false;
            }

            try {
                const selected = await invoke('choose_audio_file');

                if (!selected) {
                    return true;
                }

                selectUploadFile({
                    name: selected.name || 'audio',
                    size: Number(selected.size || 0),
                    localPath: selected.path || '',
                    durationMs: Number(selected.duration_ms || 0),
                });
            } catch (error) {
                notifyError(String(error || '').trim() || 'Could not choose this audio file.');
            }

            return true;
        };

        const loadMetadata = (file) => {
            if (metadataUrl) {
                URL.revokeObjectURL(metadataUrl);
            }

            metadataUrl = URL.createObjectURL(file);
            const audio = new Audio();
            audio.preload = 'metadata';
            audio.src = metadataUrl;

            audio.addEventListener('loadedmetadata', () => {
                selectedDurationMs = Number.isFinite(audio.duration) && audio.duration > 0
                    ? audio.duration * 1000
                    : 0;

                syncPlan();
            }, { once: true });

            audio.addEventListener('error', () => {
            selectedDurationMs = Number(file?.durationMs || 0);
                syncPlan();
            }, { once: true });
        };

        $fileInput.on('click', async function (event) {
            if (typeof tauriInvoke() !== 'function') {
                return;
            }

            event.preventDefault();
            this.value = '';
            await chooseLocalUploadFile();
        });

        $fileInput.on('change', function () {
            const file = this.files?.[0] || null;
            selectUploadFile(file);
        });

        $form.on('submit', function (event) {
            event.preventDefault();
        });

        $categoryInput.on('input change', function () {
            syncTranscriptCategory();
            renderTranscript();
            updateCleanerProgress();
            refreshUploadCategorySuggestions();
        });

        $categoryInput.on('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            closeUploadCategorySuggestions();
            syncTranscriptCategory();
            renderTranscript();
            updateCleanerProgress();
            $categoryInput.trigger('blur');
        });

        $categoryInput.on('focus click', function () {
            openUploadCategorySuggestions();
        });

        $categoryInput.on('blur', function () {
            window.setTimeout(closeUploadCategorySuggestions, 120);
        });

        $categorySuggestions.on('mousedown', '[data-upload-category-pick]', function (event) {
            event.preventDefault();
            $categoryInput.val(String($(this).attr('data-upload-category-pick') || ''));
            syncTranscriptCategory();
            renderTranscript();
            updateCleanerProgress();
            closeUploadCategorySuggestions();
        });

        $transcriptList.on('click', '[data-upload-stored-action="play"]', function () {
            const id = String($(this).closest('[data-upload-stored-item]').attr('data-upload-stored-item') || '');
            const item = uploadStoredItems.find((entry) => String(entry.id) === id);

            if (item) {
                playUploadStoredItem(item);
            }
        });

        $transcriptList.on('click', '[data-upload-stored-action="remove"]', function () {
            const id = String($(this).closest('[data-upload-stored-item]').attr('data-upload-stored-item') || '');
            const item = uploadStoredItems.find((entry) => String(entry.id) === id);

            if (item) {
                deleteUploadStoredItem(item);
            }
        });

        $chunkSize.on('change', function () {
            clampUploadChunkSize();
            preparedSections = [];
            cleanedSections = [];
            cleanerStatus = 'Waiting';
            cleanerCompletedBatches = 0;
            currentSessionId = '';
            currentCategoryName = '';
            cancelRequested = false;
            uploadCancelling = false;
            clearUploadState();
            setProcessingState(false);
            syncPlan();
            renderQueue();
        });

        $queueButton.on('click', async function () {
            if (!selectedFile || uploadInFlight || !uploadUrl || !audioChunkUrl) {
                notifyError('Upload endpoints are not ready. Refresh the page and try again.');
                return;
            }

            const categoryName = getUploadCategory();
            if (!categoryName) {
                notifyError('Choose a project name before processing the upload.');
                $categoryInput.trigger('focus');
                return;
            }

            preparedSections = buildSections();
            preparedSections = preparedSections.map((section) => ({
                ...section,
                status: 'Waiting',
                preparedMeta: selectedFile.localPath ? 'Waiting for source preparation' : 'Waiting for source upload',
            }));
            cleanedSections = [];
            cleanerStatus = 'Waiting';
            cleanerCompletedBatches = 0;
            uploadCancelling = false;
            setProcessingState(true);
            $status.text(selectedFile.localPath ? 'Preparing source' : 'Uploading source 0%');
            renderQueue();

            try {
                const response = currentSessionId
                    ? { data: { session_id: currentSessionId, sections: preparedSections.map((section) => ({
                        index: section.index,
                        start_ms: section.startMs,
                        end_ms: section.endMs,
                        duration_ms: section.durationMs,
                        range_label: section.rangeLabel,
                    })) } }
                    : await prepareUploadSession();
                sourceUploadXhr = null;
                const sessionId = String(response?.data?.session_id || '');
                const sections = Array.isArray(response?.data?.sections) ? response.data.sections : [];

                if (!sessionId || !sections.length) {
                    preparedSections = preparedSections.map((section) => ({
                        ...section,
                        status: 'Failed',
                    }));
                    $status.text('Failed');
                    renderQueue();
                    notifyError('Audio upload could not be prepared.');
                    uploadInFlight = false;
                    syncUploadControls();
                    return;
                }

                currentSessionId = sessionId;
                currentCategoryName = categoryName;
                selectedDurationMs = Number(response?.data?.duration_ms || selectedDurationMs || 0);
                syncPlan();
                preparedSections = sections.map((section, index) => ({
                    index: Number(section.index || index + 1),
                    startMs: Number(section.start_ms || 0),
                    endMs: Number(section.end_ms || 0),
                    durationMs: Number(section.duration_ms || 1),
                    rangeLabel: section.range_label || '',
                    status: 'Waiting',
                    text: '',
                    timestamps: [],
                    preparedMeta: 'Waiting for audio preparation',
                }));
                renderQueue();
                saveUploadState();

                const prepared = await prepareUploadSections(sessionId, categoryName);

                if (prepared && !pauseRequested) {
                    try {
                        await startPreparedUploadDiarization(sessionId);
                    } catch (xhr) {
                        if (cancelRequested || xhr?.statusText === 'abort') {
                            resetUploadProgress();
                            return;
                        }
                    }
                    await processUploadSections(sessionId, categoryName);
                } else if (pauseRequested) {
                    $status.text('Paused');
                    uploadInFlight = false;
                    syncUploadControls();
                } else {
                    uploadInFlight = false;
                    syncUploadControls();
                }
            } catch (xhr) {
                sourceUploadXhr = null;
                if (cancelRequested || xhr?.statusText === 'abort') {
                    resetUploadProgress();
                    return;
                }

                preparedSections = preparedSections.map((section) => ({
                    ...section,
                    status: 'Failed',
                }));
                $status.text('Failed');
                renderQueue();
                notifyError(buildUploadErrorMessage(xhr));
                uploadInFlight = false;
                syncUploadControls();
            }
        });

        $continueButton.on('click', function () {
            if (!currentSessionId || uploadInFlight || !hasUnfinishedSections()) {
                return;
            }

            cancelRequested = false;
            uploadCancelling = false;
            pauseRequested = false;
            setProcessingState(true);
            prepareUploadSections(currentSessionId, currentCategoryName || getUploadCategory())
                .then((prepared) => {
                    if (prepared && !pauseRequested) {
                        return processUploadSections(currentSessionId, currentCategoryName || getUploadCategory());
                    }
                    if (pauseRequested) {
                        $status.text('Paused');
                    }
                    uploadInFlight = false;
                    syncUploadControls();
                    return null;
                });
        });

        $retryButton.on('click', function () {
            if (!currentSessionId || uploadInFlight || !hasRetryableSections()) {
                return;
            }

            preparedSections = preparedSections.map((section) => ['Failed', 'Cancelled'].includes(section.status)
                ? {
                    ...section,
                    status: 'Waiting',
                    preparedMeta: 'Ready to retry',
                    prepared: false,
                    preparedName: '',
                    sourceName: '',
                    preparedSkipped: false,
                    noSpeech: false,
                }
                : section);
            cancelRequested = false;
            uploadCancelling = false;
            pauseRequested = false;
            setProcessingState(true);
            renderQueue();
            prepareUploadSections(currentSessionId, currentCategoryName || getUploadCategory())
                .then((prepared) => {
                    if (prepared && !pauseRequested) {
                        return processUploadSections(currentSessionId, currentCategoryName || getUploadCategory());
                    }
                    if (pauseRequested) {
                        $status.text('Paused');
                    }
                    uploadInFlight = false;
                    syncUploadControls();
                    return null;
                });
        });

        $pauseButton.on('click', function () {
            if (!uploadInFlight || !currentSessionId || pauseRequested) {
                return;
            }

            pauseRequested = true;
            $status.text('Pausing');
            syncUploadControls();
        });

        $cancelButton.on('click', function () {
            if (!uploadInFlight && !hasUploadProgress()) {
                return;
            }

            cancelRequested = true;
            pauseRequested = false;
            const speakerSessionId = currentSessionId;
            const hadActiveRequest = Boolean(sourceUploadXhr || activeSectionXhr || activePrepareXhrs.length);

            cancelWhisperProgress(activeSectionProgressId);
            showUploadCancellingState();

            if (sourceUploadXhr) {
                sourceUploadXhr.abort();
            }

            activePrepareXhrs.forEach((xhr) => xhr?.abort?.());
            activePrepareXhrs = [];

            if (activeSectionXhr) {
                activeSectionXhr.abort();
            }

            window.setTimeout(() => {
                if (cancelRequested || uploadCancelling) {
                    resetUploadProgress('Cancelled');
                }
            }, hadActiveRequest ? 350 : 0);
            releaseSpeakerSession(speakerSessionId);
        });

        window.addEventListener('pagehide', () => {
            releaseSpeakerSession(currentSessionId, true);
        });

        const restoreUploadState = () => {
            try {
                const stored = JSON.parse(window.localStorage.getItem(uploadStateStorageKey) || 'null');

                if (!stored?.sessionId || !Array.isArray(stored.sections)) {
                    return;
                }

                if (!uploadSessionStatusUrl) {
                    clearUploadState();
                    return;
                }

                $.getJSON(uploadSessionStatusUrl, { session_id: String(stored.sessionId) })
                    .done((response) => {
                        if (response?.available !== true) {
                            resetUploadProgress('Ready');
                            return;
                        }

                        currentSessionId = String(stored.sessionId);
                        currentCategoryName = String(stored.categoryName || '');
                        selectedDurationMs = Number(stored.durationMs || 0);
                        preparedSections = stored.sections.map((section) => ({
                            ...section,
                            status: section.status === 'Processing' ? 'Cancelled' : section.status,
                            preparedMeta: section.status === 'Processing' ? 'Ready to continue' : section.preparedMeta,
                        }));

                        if (!hasUnfinishedSections()) {
                            resetUploadProgress();
                            return;
                        }

                        if (currentCategoryName && !$categoryInput.val()) {
                            $categoryInput.val(currentCategoryName);
                        }
                        syncPlan();
                        syncTranscriptCategory();
                        $status.text('Ready to continue');
                        renderQueue();
                    })
                    .fail(() => resetUploadProgress('Ready'));
            } catch (error) {
                clearUploadState();
            }
        };

        $exportButton.on('click', exportTranscript);
        $logButton.on('click', loadVadLogs);
        $exportMode.on('change', renderTranscript);
        $furnishButton.on('click', furnishTranscript);

        syncPlan();
        syncTranscriptCategory();
        loadUploadCategories();
        restoreUploadState();
        renderQueue();
        $body.attr('data-upload-frontend-version', uploadFrontendVersion);
        return;
    }

    if ($body.data('page') === 'settings') {
        const $speechProviderSelect = $('[data-speech-provider-select]');
        const $speechProviderPanels = $('[data-speech-provider-panel]');
        const $serverSettingsForm = $('[data-settings-form]');
        const $serverProviderSelect = $('[data-server-provider-select]');
        const $serverModelSelect = $('[data-server-model-select]');
        const $resourceMode = $('[data-resource-mode]');
        const $resourceManualInputs = $('[data-resource-manual]');
        const $resourceGpuManualInputs = $('[data-resource-gpu-manual]');
        const syncSpeechProviderPanels = () => {
            const selectedProvider = String($speechProviderSelect.val() || 'elevenlabs');

            $speechProviderPanels.each(function () {
                const $panel = $(this);
                const isSelected = String($panel.data('speech-provider-panel') || '') === selectedProvider;

                $panel.toggleClass('hidden', !isSelected);
                $panel.find('input, select, textarea').prop('disabled', !isSelected);
            });
        };

        $speechProviderSelect.on('change', syncSpeechProviderPanels);
        syncSpeechProviderPanels();

        const syncServerModels = () => {
            if (!$serverSettingsForm.length || !$serverProviderSelect.length || !$serverModelSelect.length) {
                return;
            }

            let providers = {};

            try {
                providers = JSON.parse(String($serverSettingsForm.attr('data-provider-models') || '{}'));
            } catch (error) {
                providers = {};
            }

            const selectedProvider = String($serverProviderSelect.val() || '');
            const selectedModel = String($serverModelSelect.attr('data-selected-model') || $serverModelSelect.val() || '');
            const models = providers[selectedProvider]?.models || [];

            $serverModelSelect.empty();

            models.forEach((model) => {
                $('<option>')
                    .val(String(model.id || ''))
                    .text(String(model.label || model.id || ''))
                    .prop('selected', String(model.id || '') === selectedModel)
                    .appendTo($serverModelSelect);
            });

            if (!$serverModelSelect.val()) {
                $serverModelSelect.find('option').first().prop('selected', true);
            }
        };

        $serverProviderSelect.on('change', function () {
            $serverModelSelect.attr('data-selected-model', '');
            syncServerModels();
        });
        syncServerModels();

        const syncResourceControls = () => {
            const manual = String($resourceMode.val() || 'auto') === 'manual';
            $resourceManualInputs.prop('disabled', !manual).toggleClass('opacity-60', !manual);
            $resourceGpuManualInputs.each(function () {
                const $input = $(this);
                const enabled = manual && String($input.attr('data-gpu-available') || 'false') === 'true';

                $input.prop('disabled', !enabled).toggleClass('opacity-60', !enabled);
            });
        };

        $resourceMode.on('change', syncResourceControls);
        syncResourceControls();

        $('[data-settings-form]').on('submit', function () {
            const $saveButton = $(this).find('[data-settings-save]');

            if (typeof window.toggleLoading === 'function') {
                window.toggleLoading($saveButton, true);
                return;
            }

            $saveButton.prop('disabled', true);
        });

        return;
    }

    if ($body.data('page') !== 'live') {
        return;
    }

    const $button = $('[data-record-toggle]');
    const $state = $('[data-record-state]');
    const $caption = $('[data-record-caption]');
    const $playIcon = $('[data-record-icon="play"]');
    const $stopIcon = $('[data-record-icon="stop"]');
    const $queue = $('[data-audio-queue]');
    const $empty = $('[data-audio-empty]');
    const $count = $('[data-audio-count]');
    const $support = $('[data-audio-support]');
    const $storedList = $('[data-stored-list]');
    const $liveTranscriptBadge = $('[data-live-transcript-badge]');
    const $exportLive = $('[data-export-live]');
    const $logLive = $('[data-log-live]');
    const $categoryInput = $('[data-category-input]');
    const $categorySuggestions = $('[data-category-suggestions]');
    const $languageInput = $('[data-language-input]');
    const $currentCategory = $('[data-current-category]');
    const $activeName = $('[data-audio-active-name]');
    const $activeNote = $('[data-audio-active-note]');
    const $progress = $('[data-audio-progress]');
    const $progressLabel = $('[data-audio-progress-label]');
    const $liveContinueButton = $('[data-live-continue]');
    const $liveRetryButton = $('[data-live-retry]');
    const $liveCancelButton = $('[data-live-cancel]');
    const $liveCleanerState = $('[data-live-cleaner-state]');
    const $liveCleanerProgressLabel = $('[data-live-cleaner-progress-label]');
    const $liveCleanerProgressPercent = $('[data-live-cleaner-progress-percent]');
    const $liveCleanerProgressBar = $('[data-live-cleaner-progress-bar]');
    const $liveCleanerProgressNote = $('[data-live-cleaner-progress-note]');

    const uploadUrl = String($body.data('upload-url') || '');
    const storedUrl = String($body.data('stored-url') || '');
    const vadLogUrl = String($body.data('vad-log-url') || '');
    const playUrlBase = String($body.data('play-url-base') || '');
    const deleteUrlBase = String($body.data('delete-url-base') || '');
    const defaultUserId = Number($body.data('default-user-id') || 1);
    const segmentLengthMs = 60 * 1000;
    const supportsRecorder = Boolean(navigator.mediaDevices && window.MediaRecorder);
    const liveTimelineStorageKey = 'ai-transcriber-live-timeline-cursors';

    if (csrfToken) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        });
    }

    let isRecording = false;
    let stream = null;
    let recorder = null;
    let progressTimer = null;
    let autoStopTimer = null;
    let sessionStartedAt = 0;
    let segmentStartedAt = 0;
    let segmentIndex = 0;
    let queuedItems = [];
    let storedItems = [];
    let activeParts = [];
    let activeAudio = null;
    let activeAudioId = null;
    let activePlaybackKind = null;
    let uploadInFlight = false;
    let activeUploadXhr = null;
    let activeUploadItemId = null;
    let activeWhisperProgressId = '';
    let activeLivePhaseProgress = null;
    let activeLivePhaseTimer = null;
    let cancelUploadByUser = false;
    let uploadPaused = false;
    let liveCleanerStatus = 'Waiting';
    let activeCategoryName = String($body.data('default-category-name') || '').trim();
    let liveShowingVadLogs = false;
    let activeSpeakerSessionId = '';

    const formatClock = (milliseconds) => {
        const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;

        if (hours > 0) {
            return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }

        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    };

    const formatClipRange = (startMs, endMs) => `${formatClock(startMs)}-${formatClock(endMs)}`;

    const formatBytes = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '0 MB';
        }

        const units = ['B', 'KB', 'MB', 'GB'];
        const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / (1024 ** index);

        return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[index]}`;
    };

    const formatRelativeClock = (milliseconds) => `+${formatClock(milliseconds)}`;

    const slugify = (value) => String(value || 'transcription')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'transcription';

    const hasUsefulTranscriptText = (value) => {
        const normalized = String(value || '').trim().toLowerCase();

        return normalized !== '' && normalized !== 'no speech detected' && normalized !== 'no speech detected.';
    };

    const hasUsefulStoredTranscript = (item) => hasUsefulTranscriptText(
        item?.translatedText || item?.translated_text || item?.text || '',
    );

    const sortByTimeAscending = (first, second) => {
        const firstStart = Number(first.clipStartMs || first.clip_start_ms || 0);
        const secondStart = Number(second.clipStartMs || second.clip_start_ms || 0);

        return firstStart === secondStart
            ? Number(first.id || first.audioChunkId || first.audio_chunk_id || 0) - Number(second.id || second.audioChunkId || second.audio_chunk_id || 0)
            : firstStart - secondStart;
    };

    const downloadTextFile = (filename, content) => {
        saveTextExport(filename, content);
    };

    const notify = (message, type = 'success') => {
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
        }
    };

    const notifyError = (message) => {
        notify(message, 'error');
    };

    const buildUploadErrorMessage = (xhr, item) => {
        const clipName = item ? `Clip ${item.index}` : 'This clip';
        const serverMessage = String(xhr?.responseJSON?.message || '').trim();
        const fieldErrors = xhr?.responseJSON?.errors || {};

        if (fieldErrors.category_name?.length) {
            return 'Choose a project name before you start recording.';
        }

        if (fieldErrors.audio?.length) {
            return `${clipName} could not be saved because the audio file was not ready.`;
        }

        if (fieldErrors.duration_ms?.length) {
            return `${clipName} could not be saved because its length could not be measured.`;
        }

        if (fieldErrors.range_label?.length || fieldErrors.clip_index?.length || fieldErrors.clip_start_ms?.length || fieldErrors.clip_end_ms?.length) {
            return `${clipName} could not be saved because its time range is missing.`;
        }

        if (xhr?.status === 0) {
            return `${clipName} could not be saved because the app could not reach the local database.`;
        }

        if (serverMessage) {
            return serverMessage;
        }

        if (xhr?.status === 413) {
            return `${clipName} is too large to save right now.`;
        }

        if (xhr?.status >= 500) {
            return `${clipName} could not be saved because the local storage step failed.`;
        }

        return `${clipName} could not be saved. Please try again.`;
    };

    const buildStorageLoadErrorMessage = (xhr, selectedCategory) => {
        const serverMessage = String(xhr?.responseJSON?.message || '').trim();

        if (serverMessage) {
            return serverMessage;
        }

        const target = selectedCategory ? `for "${selectedCategory}"` : '';
        return `Could not load saved recordings ${target}.`;
    };

    const buildDeleteErrorMessage = () => 'Could not remove this clip right now.';

    const getCategoryName = () => String($categoryInput.val() || '').trim();

    const getLanguageCode = () => getTranscriptionEngine() === 'offline'
        ? 'auto'
        : String($languageInput.val() || 'multi').trim() || 'multi';

    const escapeHtml = (value) => $('<div>').text(String(value || '')).html();

    const loadLiveTimelineCursors = () => {
        try {
            const decoded = JSON.parse(window.localStorage.getItem(liveTimelineStorageKey) || '{}');

            return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
        } catch (error) {
            return {};
        }
    };

    const liveTimelineKey = (categoryName) => String(categoryName || '').trim().toLowerCase();

    const getLiveTimelineCursor = (categoryName) => {
        const key = liveTimelineKey(categoryName);

        if (!key) {
            return 0;
        }

        const cursors = loadLiveTimelineCursors();
        const endMs = Number(cursors[key] || 0);

        return Number.isFinite(endMs) ? Math.max(0, endMs) : 0;
    };

    const rememberLiveTimelineCursor = (categoryName, endMs) => {
        const key = liveTimelineKey(categoryName);
        const nextEndMs = Number(endMs || 0);

        if (!key || !Number.isFinite(nextEndMs) || nextEndMs <= 0) {
            return;
        }

        const cursors = loadLiveTimelineCursors();
        cursors[key] = Math.max(Number(cursors[key] || 0), nextEndMs);

        try {
            window.localStorage.setItem(liveTimelineStorageKey, JSON.stringify(cursors));
        } catch (error) {
        }
    };

    const exportStoredTranscription = () => {
        const useCleaned = $('[data-export-live-mode]').val() === 'clean';
        const cleanedPayload = window.liveCleanedTranscriptPayload || {};
        const selectedCategory = getCategoryName();
        const items = (useCleaned
            ? (cleanedPayload.categoryName === selectedCategory ? cleanedPayload.items || [] : [])
            : getStoredItemsForCategory())
            .filter((item) => hasUsefulTranscriptText(item.cleanText || item.clean_text || item.translatedText || item.translated_text || item.text || ''))
            .slice()
            .sort(sortByTimeAscending);

        if (!items.length) {
            notifyError(useCleaned
                ? 'Polish the transcript before exporting the cleaned version.'
                : 'No transcription is ready to export yet.');
            return;
        }

        const content = items
            .map((item) => `${item.rangeLabel || item.range_label || ''}\n${item.cleanText || item.clean_text || item.translatedText || item.translated_text || ''}`)
            .join('\n\n');

        downloadTextFile(`${slugify(selectedCategory)}-${useCleaned ? 'cleaned' : 'raw'}-transcription.txt`, content);
    };

    const renderVadLogs = (logs, categoryName) => {
        liveShowingVadLogs = true;
        $liveTranscriptBadge.text(String(logs.length));

        if (!logs.length) {
            $storedList.html(`
                <div class="w-full py-4">
                    <p class="text-sm text-slate-200">No processing logs found for ${escapeHtml(categoryName)}.</p>
                </div>
            `);
            return;
        }

        $storedList.html(logs.map((log) => {
            const segments = Array.isArray(log.speech_segments) ? log.speech_segments : [];
            const statusClasses = log.speech_detected
                ? 'border-emerald-300/20 bg-emerald-300/10 text-emerald-100'
                : 'border-amber-300/20 bg-amber-300/10 text-amber-100';
            const statusLabel = log.speech_detected ? 'Speech' : 'No speech';
            const segmentHtml = segments.length
                ? segments.map((segment, index) => `
                    <div class="rounded-lg border border-white/10 bg-white/[0.03] px-2.5 py-2">
                        <p class="text-[0.66rem] font-semibold uppercase tracking-[0.18em] text-cyan-300">Segment ${index + 1}</p>
                        <p class="mt-1 text-xs text-white">${formatClock(Number(segment.absolute_start_ms || 0))}-${formatClock(Number(segment.absolute_end_ms || 0))}</p>
                        <p class="mt-0.5 text-[0.68rem] text-slate-400">Inside clip ${formatRelativeClock(Number(segment.start_ms || 0))}-${formatRelativeClock(Number(segment.end_ms || 0))}</p>
                    </div>
                `).join('')
                : '<p class="text-xs text-slate-400">Hosted transcription skipped for this range.</p>';

            return `
                <article class="w-full border-b border-white/8 py-2.5 last:border-b-0">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-xs font-medium leading-5 tracking-[0.14em] text-cyan-300">${escapeHtml(log.range_label || '')}</p>
                            <p class="mt-0.5 text-[0.68rem] text-slate-400">Clip ${Number(log.clip_index || 0)} · ${formatClock(Number(log.clip_start_ms || 0))}-${formatClock(Number(log.clip_end_ms || 0))}</p>
                        </div>
                        <span class="rounded-lg border px-2 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.16em] ${statusClasses}">${statusLabel}</span>
                    </div>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        ${segmentHtml}
                    </div>
                    <p class="mt-2 text-[0.68rem] text-slate-500">Speech ${formatClock(Number(log.speech_duration_ms || 0))} · Filtered ${formatBytes(Number(log.filtered_size_bytes || 0))}</p>
                </article>
            `;
        }).join(''));
    };

    const restoreLogButton = () => {
        $logLive.prop('disabled', false).html(liveShowingVadLogs ? `
            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M15 18l-6-6 6-6" />
            </svg>
            Transcript
        ` : `
            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M8 6h13" />
                <path d="M8 12h13" />
                <path d="M8 18h13" />
                <path d="M3 6h.01" />
                <path d="M3 12h.01" />
                <path d="M3 18h.01" />
            </svg>
            Log
        `);
    };

    const loadVadLogs = () => {
        if (liveShowingVadLogs) {
            liveShowingVadLogs = false;
            restoreLogButton();
            renderStoredList();
            return;
        }

        const categoryName = getCategoryName();

        if (!categoryName) {
            notifyError('Choose a project name before loading processing logs.');
            return;
        }

        if (!vadLogUrl) {
            notifyError('Processing logs are unavailable.');
            return;
        }

        $logLive.prop('disabled', true).text('Loading');

        $.getJSON(vadLogUrl, {
            category_name: categoryName,
            source_type: 'live',
        })
            .done((response) => {
                renderVadLogs(Array.isArray(response?.data) ? response.data : [], categoryName);
            })
            .fail((xhr) => {
                notifyError(String(xhr?.responseJSON?.message || 'Could not load processing logs.'));
            })
            .always(restoreLogButton);
    };

    const furnishStoredTranscription = async () => {
        const categoryName = getCategoryName();
        const furnishUrl = String($body.attr('data-furnish-url') || '');

        if (!categoryName) {
            notifyError('Choose a project name before polishing the transcript.');
            return;
        }

        if (!getStoredItemsForCategory().length) {
            notifyError('No raw transcript is ready to polish yet.');
            return;
        }

        if (!furnishUrl) {
            notifyError('Transcript polishing is unavailable.');
            return;
        }

        const instructions = await requestPolishInstructions();

        if (!instructions) {
            return;
        }

        const $button = $('[data-furnish-live]');
        $button.prop('disabled', true).text('Polishing');
        liveCleanerStatus = 'Polishing';
        renderStoredList();
        updateLiveCleanerProgress();

        $.ajax({
            url: furnishUrl,
            method: 'POST',
            data: {
                user_id: defaultUserId,
                category_name: categoryName,
                instructions,
            },
            success: (response) => {
                const rows = Array.isArray(response?.data) ? response.data : [];
                window.liveCleanedTranscriptPayload = {
                    categoryName,
                    items: rows.map((row) => ({
                        audioChunkId: row.audio_chunk_id,
                        clipStartMs: row.clip_start_ms,
                        rangeLabel: row.range_label || '',
                        cleanText: row.clean_text || '',
                        cleanTimestamps: row.clean_timestamps || [],
                    })),
                };
                liveCleanerStatus = 'Complete';
                renderStoredList();
                notify('Transcript polished.');
            },
            error: (xhr) => {
                liveCleanerStatus = 'Failed';
                updateLiveCleanerProgress();
                notifyError(String(xhr?.responseJSON?.message || 'Transcript could not be polished.'));
            },
            complete: () => {
                $button.prop('disabled', false).text('Polish');
            },
        });
    };

    const setCategoryBadge = (value) => {
        $currentCategory.text(value || 'Choose project');
    };

    const syncRecordButtonState = () => {
        const shouldDisable = !supportsRecorder;

        $button.prop('disabled', shouldDisable);
        $button.toggleClass('cursor-not-allowed opacity-60', shouldDisable);
        $button.toggleClass('hover:scale-[1.01]', !shouldDisable);
    };

    const syncCategoryUi = () => {
        setCategoryBadge(getCategoryName());

        if (!isRecording) {
            syncRecordButtonState();
            if (!uploadPaused) {
                setSupportMessage(getCategoryName() ? 'Ready' : 'Choose project');
            }
        }
    };

    const syncCategoryOptions = () => {
        return [...new Set(storedItems.map((item) => item.categoryName).filter(Boolean))];
    };

    const getStoredItemsForCategory = () => {
        const selectedCategory = getCategoryName().toLowerCase();

        if (!selectedCategory) {
            return [];
        }

        return storedItems
            .filter((item) => String(item.categoryName || '').toLowerCase() === selectedCategory)
            .filter(hasUsefulStoredTranscript)
            .sort(sortByTimeAscending);
    };

    const getLiveCleanerBatchCount = () => {
        const items = getStoredItemsForCategory();

        if (!items.length) {
            return 0;
        }

        return new Set(items.map((item) => Math.floor(Number(item.clipStartMs || 0) / (5 * 60 * 1000)))).size;
    };

    const updateLiveCleanerProgress = () => {
        const total = getLiveCleanerBatchCount();
        const selectedCategory = getCategoryName();
        const cleanedPayload = window.liveCleanedTranscriptPayload || {};
        const hasCleanedCategory = selectedCategory !== ''
            && String(cleanedPayload.categoryName || '').toLowerCase() === selectedCategory.toLowerCase()
            && Array.isArray(cleanedPayload.items)
            && cleanedPayload.items.length > 0;

        if (liveCleanerStatus !== 'Polishing' && liveCleanerStatus !== 'Failed') {
            liveCleanerStatus = hasCleanedCategory ? 'Complete' : 'Waiting';
        }

        const complete = liveCleanerStatus === 'Complete';
        const done = complete ? total : 0;
        const percent = total > 0 && complete ? 100 : 0;

        $liveCleanerState.text(liveCleanerStatus);
        $liveCleanerProgressLabel.text(`${done} / ${total} batches`);
        $liveCleanerProgressPercent.text(`${percent}%`);
        $liveCleanerProgressBar.css('width', `${percent}%`);

        if (liveCleanerStatus === 'Polishing') {
            $liveCleanerProgressNote.text(`Polishing ${total} five-minute ${total === 1 ? 'batch' : 'batches'}.`);
        } else if (liveCleanerStatus === 'Complete') {
            $liveCleanerProgressNote.text('The polished transcript is ready for viewing and export.');
        } else if (liveCleanerStatus === 'Failed') {
            $liveCleanerProgressNote.text('Polishing failed. You can polish the transcript again.');
        } else {
            $liveCleanerProgressNote.text(total > 0
                ? `${total} five-minute ${total === 1 ? 'batch is' : 'batches are'} ready to polish.`
                : 'Record or load a raw transcript before polishing.');
        }
    };

    const getLatestStoredEndMsForCategory = () => {
        const items = getStoredItemsForCategory();

        if (!items.length) {
            return 0;
        }

        return items.reduce((latest, item) => {
            const endMs = Number(item.clipEndMs || item.clip_end_ms || 0);
            return Number.isFinite(endMs) ? Math.max(latest, endMs) : latest;
        }, 0);
    };

    const getLatestTimelineEndMsForCategory = () => {
        const latestStoredEndMs = getLatestStoredEndMsForCategory();

        if (latestStoredEndMs <= 0) {
            return 0;
        }

        return Math.max(
            latestStoredEndMs,
            getLiveTimelineCursor(getCategoryName()),
        );
    };

    const syncRecordingTimeline = () => {
        if (isRecording) {
            return;
        }

        const latestEndMs = getLatestTimelineEndMsForCategory();
        sessionStartedAt = latestEndMs > 0 ? Date.now() - latestEndMs : 0;
    };

    const renderCategorySuggestions = () => {
        const categories = syncCategoryOptions();
        const currentValue = getCategoryName().toLowerCase();
        const filtered = currentValue
            ? categories.filter((category) => category.toLowerCase().includes(currentValue))
            : categories;

        if (!filtered.length) {
            $categorySuggestions.html('<div class="px-3 py-2 text-sm text-slate-500">No project names yet.</div>');
            return;
        }

        $categorySuggestions.html(filtered
            .map((category) => `
                <button
                    type="button"
                    data-category-pick="${escapeHtml(category)}"
                    class="flex w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm text-white transition hover:bg-white/8"
                >
                    ${escapeHtml(category)}
                </button>
            `)
            .join(''));
    };

    const openCategorySuggestions = () => {
        renderCategorySuggestions();
        $categorySuggestions.removeClass('hidden');
    };

    const closeCategorySuggestions = () => {
        $categorySuggestions.addClass('hidden');
    };

    const refreshCategorySuggestions = () => {
        if (!$categorySuggestions.hasClass('hidden')) {
            renderCategorySuggestions();
        }
    };

    const getCurrentClipRange = () => {
        if (!sessionStartedAt) {
            return '00:00-01:00';
        }

        const elapsed = Math.max(0, Date.now() - sessionStartedAt);
        const clipStart = Math.floor(elapsed / segmentLengthMs) * segmentLengthMs;
        const clipEnd = clipStart + segmentLengthMs;

        return formatClipRange(clipStart, clipEnd);
    };

    const getUploadStateMeta = (state) => {
        switch (state) {
            case 'sending':
                return {
                    label: 'Sending',
                    classes: 'border-cyan-300/20 bg-cyan-300/10 text-cyan-100',
                };
            case 'saved':
                return {
                    label: 'Saved',
                    classes: 'border-emerald-300/20 bg-emerald-300/10 text-emerald-100',
                };
            case 'error':
                return {
                    label: 'Error',
                    classes: 'border-rose-300/20 bg-rose-300/10 text-rose-100',
                };
            case 'waiting':
            default:
                return {
                    label: 'Waiting',
                    classes: 'border-white/10 bg-white/[0.05] text-slate-300',
                };
        }
    };

    const setSupportMessage = (message) => {
        $support.text(message);
    };

    const hasLiveWaitingClips = () => queuedItems.some((item) => item.uploadState === 'waiting');

    const hasLiveRetryableClips = () => queuedItems.some((item) => item.uploadState === 'error');

    const hasLiveCancelableClips = () => queuedItems.some((item) => ['waiting', 'sending'].includes(item.uploadState));

    const syncLiveControls = () => {
        $liveContinueButton.prop('disabled', uploadInFlight || !uploadPaused || !hasLiveWaitingClips());
        $liveRetryButton.prop('disabled', uploadInFlight || !hasLiveRetryableClips());
        $liveCancelButton.prop('disabled', !uploadInFlight && !hasLiveCancelableClips());
    };

    const setIdleUi = () => {
        $button.attr('data-recording', 'false').attr('aria-pressed', 'false');
        $state.text('Listening').removeClass('text-rose-300').addClass('text-cyan-300');
        $caption.text('Ready to capture').removeClass('text-rose-50').addClass('text-white');
        $playIcon.removeClass('hidden');
        $stopIcon.addClass('hidden');
        $activeName.text('Ready');
        $activeNote.text('');
        $progress.css('width', '0%');
        $progressLabel.text('00:00:00');
        $categoryInput.prop('disabled', false);
        $languageInput.prop('disabled', false);
        syncCategoryUi();
    };

    const setRecordingUi = () => {
        $button.attr('data-recording', 'true').attr('aria-pressed', 'true');
        $state.text('Recording').removeClass('text-cyan-300').addClass('text-rose-300');
        $caption.text('Stop recording').removeClass('text-white').addClass('text-rose-50');
        $playIcon.addClass('hidden');
        $stopIcon.removeClass('hidden');
        $categoryInput.prop('disabled', true);
        $languageInput.prop('disabled', true);
        syncRecordButtonState();
        setSupportMessage(uploadInFlight ? 'Sending' : 'Live');
    };

    const updateQueueSummary = () => {
        $count.text(String(queuedItems.length));

        if ($empty.length) {
            $empty.toggleClass('hidden', queuedItems.length > 0);
        }

        syncLiveControls();
    };

    const updateStoredSummary = (count) => {
        $liveTranscriptBadge.text(String(count));

        $storedList.find('[data-stored-empty]').toggleClass('hidden', count > 0);
    };

    const updateQueueItemProgress = (itemId, currentMs, durationMs) => {
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);
        if (!$row.length) {
            return;
        }

        const safeDuration = Math.max(durationMs || 1, 1);
        const percent = Math.max(0, Math.min(100, (currentMs / safeDuration) * 100));

        $row.find('[data-item-progress]').css('width', `${percent}%`);
        $row.find('[data-item-progress-label]').text(`${formatClock(currentMs)} / ${formatClock(safeDuration)}`);
    };

    const getLivePhaseDefinitions = (transcribeLabel = 'Server') => [
        { key: 'prepare', label: 'Prepare' },
        { key: 'silero', label: 'Silero' },
        { key: 'transcribe', label: transcribeLabel },
        { key: 'sherpa', label: 'Sherpa' },
    ];

    const livePhaseAverage = (phases, transcribeLabel = 'Server') => phaseProgressAverage(
        phases,
        getLivePhaseDefinitions(transcribeLabel),
    );

    const livePhaseSummary = (phases, transcribeLabel = 'Server') => phaseProgressSummary(
        phases,
        getLivePhaseDefinitions(transcribeLabel),
    );

    const updateLivePhaseProgress = (itemId, updates = {}, activeLabel = '') => {
        const item = queuedItems.find((entry) => entry.id === itemId);
        if (!item) {
            return;
        }

        item.phaseProgress = {
            prepare: 0,
            silero: 0,
            transcribe: 0,
            sherpa: 0,
            ...(item.phaseProgress || {}),
            ...updates,
        };
        activeLivePhaseProgress = item.phaseProgress;

        const transcribeLabel = item.transcriptionEngine === 'offline' ? 'Whisper' : 'Server';
        const percent = livePhaseAverage(item.phaseProgress);
        const summary = livePhaseSummary(item.phaseProgress, transcribeLabel);
        const label = activeLabel || summary;
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);

        if ($row.length) {
            $row.find('[data-item-progress]').css('width', `${percent}%`);
            $row.find('[data-item-progress-label]').text(summary);
            $row.find('[data-upload-state]').text(`${percent}%`);
        }

        if (!isRecording) {
            $activeName.text('Processing');
            $activeNote.text(summary);
            $progress.css('width', `${percent}%`);
            $progressLabel.text(`${percent}%`);
        }
        setSupportMessage(isRecording ? `Live - ${label}` : label);
    };

    const stopLivePhaseTimer = () => {
        if (activeLivePhaseTimer) {
            window.clearInterval(activeLivePhaseTimer);
            activeLivePhaseTimer = null;
        }
    };

    const startLivePhaseTimer = (item) => {
        stopLivePhaseTimer();
        activeLivePhaseProgress = item.phaseProgress || {
            prepare: 0,
            silero: 0,
            transcribe: 0,
            sherpa: 0,
        };
        item.phaseProgress = activeLivePhaseProgress;

        activeLivePhaseTimer = window.setInterval(() => {
            if (activeUploadItemId !== item.id || !uploadInFlight) {
                stopLivePhaseTimer();
                return;
            }

            const next = { ...(item.phaseProgress || {}) };
            let label = '';

            if ((next.prepare || 0) < 95) {
                next.prepare = Math.min(95, Number(next.prepare || 0) + 8);
                label = 'Prepare';
            } else if ((next.silero || 0) < 95) {
                next.prepare = 100;
                next.silero = Math.min(95, Number(next.silero || 0) + 5);
                label = 'Silero';
            } else if ((next.transcribe || 0) < 95) {
                next.silero = 100;
                next.transcribe = Math.min(95, Number(next.transcribe || 0) + (item.transcriptionEngine === 'offline' ? 1 : 3));
                label = item.transcriptionEngine === 'offline' ? 'Whisper' : 'Server';
            } else if ((next.sherpa || 0) < 90) {
                next.transcribe = 100;
                next.sherpa = Math.min(90, Number(next.sherpa || 0) + 2);
                label = 'Sherpa';
            } else {
                label = 'Sherpa';
            }

            updateLivePhaseProgress(item.id, next, `${label} ${livePhaseAverage(next)}%`);
        }, 450);
    };

    const updateQueueItemTranscriptionProgress = (itemId, whisperPercent) => {
        updateLivePhaseProgress(itemId, {
            prepare: 100,
            silero: 100,
            transcribe: Math.max(0, Math.min(100, Number(whisperPercent || 0))),
        }, `Whisper ${Math.round(whisperPercent)}%`);
    };

    const setQueueItemPlaybackState = (itemId, playing) => {
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);
        if (!$row.length) {
            return;
        }

        $row.find('[data-play-icon="play"]').toggleClass('hidden', playing);
        $row.find('[data-play-icon="pause"]').toggleClass('hidden', !playing);
        $row.find('[data-play-label]').text(playing ? 'Pause' : 'Play');
        $row.toggleClass('border-cyan-300/20 bg-cyan-300/5', playing);
    };

    const setStoredItemPlaybackState = (itemId, playing) => {
        const $row = $storedList.find(`[data-stored-item="${itemId}"]`);
        if (!$row.length) {
            return;
        }

        $row.find('[data-stored-play-icon="play"]').toggleClass('hidden', playing);
        $row.find('[data-stored-play-icon="pause"]').toggleClass('hidden', !playing);
        $row.find('[data-stored-play-label]').text(playing ? 'Pause' : 'Play');
        $row.toggleClass('border-cyan-300/20 bg-cyan-300/5', playing);
    };

    const renderStoredList = () => {
        const selectedCategory = getCategoryName();
        const rawItems = getStoredItemsForCategory();
        const useCleaned = $('[data-export-live-mode]').val() === 'clean';
        const cleanedPayload = window.liveCleanedTranscriptPayload || {};
        const items = useCleaned
            ? (cleanedPayload.categoryName === selectedCategory
                ? (cleanedPayload.items || [])
                    .filter((item) => hasUsefulTranscriptText(item.cleanText || item.clean_text || ''))
                    .sort(sortByTimeAscending)
                : [])
            : rawItems;
        syncRecordingTimeline();
        updateStoredSummary(rawItems.length);
        renderCategorySuggestions();

        const html = items
            .map((item) => {
                const rangeLabel = item.rangeLabel || item.range_label || '';
                const translatedText = useCleaned
                    ? item.cleanText || item.clean_text || ''
                    : item.translatedText || item.translated_text || '';
                const transcriptTimestamps = useCleaned
                    ? (item.cleanTimestamps || item.clean_timestamps || [])
                    : (item.timestamps || item.transcription_timestamps || []);
                return `
                    <article data-stored-item="${item.id}" class="w-full border-b border-white/8 py-2.5 last:border-b-0">
                        <div class="flex w-full flex-col gap-2.5 md:flex-row md:items-start md:gap-4">
                            <div class="flex shrink-0 items-start gap-2 md:w-[12.5rem]">
                                <p class="max-w-full text-xs font-medium leading-5 tracking-[0.14em] text-cyan-300">${rangeLabel}</p>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        data-stored-action="play"
                                        class="group inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
                                    >
                                        <span data-stored-play-icon="play" class="text-emerald-300">
                                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                                <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                            </svg>
                                        </span>
                                        <span data-stored-play-icon="pause" class="hidden text-rose-400">
                                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                                <rect x="6.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                                <rect x="13.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                            </svg>
                                        </span>
                                        <span class="sr-only" data-stored-play-label>Play</span>
                                    </button>
                                    <button
                                        type="button"
                                        data-stored-action="remove"
                                        class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg border border-rose-400/20 bg-rose-400/10 text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
                                    >
                                        <span class="sr-only">Delete</span>
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                            <path d="M9 3.5h6a1.5 1.5 0 0 1 1.5 1.5V6h2.5a1 1 0 1 1 0 2h-.58l-.78 10.01A2.5 2.5 0 0 1 15.17 20H8.83a2.5 2.5 0 0 1-2.49-1.99L5.56 8H5a1 1 0 1 1 0-2h2.5V5A1.5 1.5 0 0 1 9 3.5Zm1 2V6h4V5.5h-4ZM7.58 8l.77 9.83c.04.45.42.79.87.79h6.56c.45 0 .83-.34.87-.79L17.42 8H7.58Z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="min-w-0 flex-1">
                                ${renderTranscriptText(translatedText, transcriptTimestamps)}
                            </div>
                        </div>
                    </article>
                `;
            })
            .join('');

        $storedList.html(`
            <div data-stored-empty class="w-full py-4 ${items.length > 0 ? 'hidden' : ''}">
                <p class="text-sm text-slate-200">${useCleaned && rawItems.length ? 'Polish the transcript before viewing cleaned text.' : (selectedCategory ? 'No entries yet.' : 'Choose a project name.')}</p>
            </div>
            ${html}
        `);

        syncCategoryOptions();
        updateLiveCleanerProgress();

        if (activeAudioId && activePlaybackKind === 'stored') {
            setStoredItemPlaybackState(activeAudioId, true);
        }
    };

    const setQueueItemUploadState = (itemId, state) => {
        const item = queuedItems.find((entry) => entry.id === itemId);
        if (!item) {
            return;
        }

        item.uploadState = state;
        syncLiveControls();

        const meta = getUploadStateMeta(state);
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);

        if ($row.length) {
            const $pill = $row.find('[data-upload-state]');
            $pill
                .text(meta.label)
                .attr('class', `inline-flex items-center rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.24em] ${meta.classes}`);
        }
    };

    const stopActiveAudio = (resetProgress = false) => {
        if (!activeAudio) {
            return;
        }

        const itemId = activeAudioId;
        activeAudio.pause();
        activeAudio.currentTime = 0;

        if (itemId) {
            if (activePlaybackKind === 'stored') {
                setStoredItemPlaybackState(itemId, false);
            } else {
                setQueueItemPlaybackState(itemId, false);
            }

            if (resetProgress) {
                const item = activePlaybackKind === 'stored'
                    ? storedItems.find((entry) => entry.id === itemId)
                    : queuedItems.find((entry) => entry.id === itemId);
                if (item) {
                    updateQueueItemProgress(itemId, 0, item.durationMs || 1000);
                }
            }
        }

        activeAudio = null;
        activeAudioId = null;
        activePlaybackKind = null;
    };

    const processUploadQueue = () => {
        if (uploadPaused || uploadInFlight || !uploadUrl) {
            syncLiveControls();
            return;
        }

        const nextItem = queuedItems.find((entry) => entry.uploadState === 'waiting');
        if (!nextItem) {
            if (isRecording) {
                setSupportMessage('Live');
            } else if (!uploadPaused) {
                setSupportMessage('Ready');
                $activeName.text('Ready');
                $activeNote.text('');
                $progress.css('width', '0%');
                $progressLabel.text('00:00:00');
            }
            syncLiveControls();
            return;
        }

        uploadInFlight = true;
        activeUploadItemId = nextItem.id;
        const transcriptionEngine = getTranscriptionEngine();
        nextItem.transcriptionEngine = transcriptionEngine;
        nextItem.phaseProgress = {
            prepare: 0,
            silero: 0,
            transcribe: 0,
            sherpa: 0,
        };
        setQueueItemUploadState(nextItem.id, 'sending');
        setSupportMessage('Sending');
        syncLiveControls();
        updateLivePhaseProgress(nextItem.id, nextItem.phaseProgress, 'Prepare 0%');
        startLivePhaseTimer(nextItem);

        const offline = transcriptionEngine === 'offline';
        const progressId = offline ? createTranscriptionProgressId() : '';
        activeWhisperProgressId = progressId;
        if (progressId) {
            registerWhisperProgress(progressId, (percent) => {
                updateQueueItemTranscriptionProgress(nextItem.id, percent);
            });
        }

        const formData = new FormData();
        formData.append('audio', nextItem.blob, `clip-${nextItem.index}.webm`);
        formData.append('user_id', String(defaultUserId));
        formData.append('category_name', nextItem.categoryName || activeCategoryName || getCategoryName());
        formData.append('clip_index', String(nextItem.index));
        formData.append('clip_start_ms', String(nextItem.clipStartMs));
        formData.append('clip_end_ms', String(nextItem.clipEndMs));
        formData.append('range_label', nextItem.rangeLabel);
        formData.append('duration_ms', String(nextItem.durationMs));
        formData.append('language_code', nextItem.languageCode || getLanguageCode());
        formData.append('transcription_engine', transcriptionEngine);
        formData.append('whisper_model', getWhisperModel());
        formData.append('speaker_session_id', nextItem.speakerSessionId || '');
        formData.append('finalize_session', nextItem.finalizeSession ? '1' : '0');
        if (progressId) {
            formData.append('progress_id', progressId);
        }

        activeUploadXhr = $.ajax({
            url: uploadUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: () => {
                const xhr = $.ajaxSettings.xhr();

                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', (event) => {
                        if (!event.lengthComputable) {
                            return;
                        }

                        const percent = Math.max(0, Math.min(100, (event.loaded / event.total) * 100));
                        updateLivePhaseProgress(nextItem.id, {
                            prepare: percent,
                        }, `Prepare ${Math.round(livePhaseAverage({
                            ...(nextItem.phaseProgress || {}),
                            prepare: percent,
                        }))}%`);
                    });
                }

                return xhr;
            },
            success: (response) => {
                const responseData = response?.data || {};
                updateLivePhaseProgress(nextItem.id, {
                    prepare: 100,
                    silero: 100,
                    transcribe: 100,
                    sherpa: 100,
                }, 'Complete 100%');
                rememberLiveTimelineCursor(
                    nextItem.categoryName || activeCategoryName || getCategoryName(),
                    Number(responseData.clip_end_ms || nextItem.clipEndMs || 0),
                );
                setSupportMessage('Saved');
                loadStoredClips();
                removeQueuedItem(nextItem.id, { skipAbort: true });
            },
            error: (_xhr, status) => {
                if (status === 'abort' && cancelUploadByUser) {
                    cancelUploadByUser = false;
                    return;
                }

                setQueueItemUploadState(nextItem.id, 'error');
                uploadPaused = true;
                const errorMessage = buildUploadErrorMessage(_xhr, nextItem);
                setSupportMessage('Save failed');
                notifyError(errorMessage);
                stopRecording();
                syncLiveControls();
            },
            complete: () => {
                stopLivePhaseTimer();
                clearWhisperProgress(activeWhisperProgressId);
                activeWhisperProgressId = '';
                activeLivePhaseProgress = null;
                uploadInFlight = false;
                activeUploadXhr = null;
                activeUploadItemId = null;
                syncLiveControls();

                if (!uploadPaused) {
                    processUploadQueue();
                }
            },
        });
    };

    const removeQueuedItem = (id, options = {}) => {
        const { skipAbort = false } = options;
        const index = queuedItems.findIndex((item) => item.id === id);
        if (index === -1) {
            return;
        }

        const [item] = queuedItems.splice(index, 1);

        if (activeAudioId === id) {
            stopActiveAudio(false);
        }

        if (!skipAbort && activeUploadItemId === id && activeUploadXhr) {
            cancelUploadByUser = true;
            stopLivePhaseTimer();
            cancelWhisperProgress(activeWhisperProgressId);
            activeUploadXhr.abort();
        }

        if (item.url) {
            URL.revokeObjectURL(item.url);
        }

        if (!skipAbort
            && item.finalizeSession
            && item.speakerSessionId
            && !queuedItems.some((queued) => queued.speakerSessionId === item.speakerSessionId)) {
            releaseSpeakerSession(item.speakerSessionId);
        }

        renderQueue();

        if (!uploadPaused) {
            processUploadQueue();
        }

        syncLiveControls();
    };

    const cancelLiveQueue = () => {
        const removableItems = queuedItems.filter((item) => ['waiting', 'sending'].includes(item.uploadState));
        const sessionIds = [...new Set([
            activeSpeakerSessionId,
            ...queuedItems.map((item) => item.speakerSessionId),
        ].filter(Boolean))];

        if (!removableItems.length && !uploadInFlight && !sessionIds.length) {
            return;
        }

        if (activeUploadXhr) {
            cancelUploadByUser = true;
            stopLivePhaseTimer();
            cancelWhisperProgress(activeWhisperProgressId);
            activeUploadXhr.abort();
        }

        removableItems.forEach((item) => {
            if (activeAudioId === item.id && activePlaybackKind === 'live') {
                stopActiveAudio(false);
            }

            if (item.url) {
                URL.revokeObjectURL(item.url);
            }
        });

        queuedItems = queuedItems.filter((item) => !['waiting', 'sending'].includes(item.uploadState));
        sessionIds.forEach((sessionId) => releaseSpeakerSession(sessionId));
        activeSpeakerSessionId = '';
        uploadInFlight = false;
        activeUploadItemId = null;
        activeLivePhaseProgress = null;
        uploadPaused = hasLiveRetryableClips();
        setSupportMessage(uploadPaused ? 'Saving paused' : (isRecording ? 'Live' : 'Ready'));
        renderQueue();
        syncLiveControls();
    };

    const continueLiveQueue = () => {
        if (uploadInFlight || !hasLiveWaitingClips()) {
            return;
        }

        uploadPaused = false;
        setSupportMessage('Ready');
        syncLiveControls();
        processUploadQueue();
    };

    const retryLiveQueue = () => {
        if (uploadInFlight || !hasLiveRetryableClips()) {
            return;
        }

        queuedItems = queuedItems.map((item) => item.uploadState === 'error'
            ? {
                ...item,
                uploadState: 'waiting',
            }
            : item);
        uploadPaused = false;
        renderQueue();
        processUploadQueue();
    };

    const playQueuedItem = (item) => {
        if (activeAudioId === item.id && activeAudio) {
            if (activeAudio.paused) {
                activeAudio.play();
                setQueueItemPlaybackState(item.id, true);
            } else {
                activeAudio.pause();
                setQueueItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveAudio(false);

        activeAudio = new Audio(item.url);
        activeAudio.preload = 'metadata';
        activeAudioId = item.id;
        activePlaybackKind = 'live';

        const syncDuration = () => {
            const durationMs = Number.isFinite(activeAudio.duration) && activeAudio.duration > 0
                ? activeAudio.duration * 1000
                : item.durationMs || 1000;

            item.durationMs = durationMs;
            updateQueueItemProgress(item.id, activeAudio.currentTime * 1000, durationMs);
        };

        activeAudio.addEventListener('loadedmetadata', syncDuration);
        activeAudio.addEventListener('timeupdate', () => {
            const durationMs = Number.isFinite(activeAudio.duration) && activeAudio.duration > 0
                ? activeAudio.duration * 1000
                : item.durationMs || 1000;

            updateQueueItemProgress(item.id, activeAudio.currentTime * 1000, durationMs);
        });
        activeAudio.addEventListener('pause', () => {
            if (activeAudioId === item.id && activeAudio.paused) {
                setQueueItemPlaybackState(item.id, false);
            }
        });
        activeAudio.addEventListener('ended', () => {
            const durationMs = Number.isFinite(activeAudio.duration) && activeAudio.duration > 0
                ? activeAudio.duration * 1000
                : item.durationMs || 1000;

            updateQueueItemProgress(item.id, durationMs, durationMs);
            setQueueItemPlaybackState(item.id, false);
            activeAudio = null;
            activeAudioId = null;
            activePlaybackKind = null;
        });

        activeAudio.play();
        setQueueItemPlaybackState(item.id, true);
    };

    const playStoredItem = (item) => {
        if (activeAudioId === item.id && activeAudio) {
            if (activeAudio.paused) {
                activeAudio.play();
                setStoredItemPlaybackState(item.id, true);
            } else {
                activeAudio.pause();
                setStoredItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveAudio(false);

        activeAudio = new Audio(item.playUrl || `${playUrlBase}/${item.id}/audio`);
        activeAudio.preload = 'metadata';
        activeAudioId = item.id;
        activePlaybackKind = 'stored';

        activeAudio.addEventListener('ended', () => {
            setStoredItemPlaybackState(item.id, false);
            activeAudio = null;
            activeAudioId = null;
            activePlaybackKind = null;
        });
        activeAudio.addEventListener('pause', () => {
            if (activeAudioId === item.id && activeAudio.paused) {
                setStoredItemPlaybackState(item.id, false);
            }
        });

        activeAudio.play();
        setStoredItemPlaybackState(item.id, true);
    };

    const deleteStoredItem = (item) => {
        const deleteUrl = item.deleteUrl || `${deleteUrlBase}/${item.id}`;

        if (activeAudioId === item.id && activePlaybackKind === 'stored') {
            stopActiveAudio(false);
        }

        $.ajax({
            url: deleteUrl,
            method: 'DELETE',
            success: () => {
                storedItems = storedItems.filter((entry) => entry.id !== item.id);
                renderStoredList();
                setSupportMessage(isRecording ? 'Live' : 'Ready');
            },
            error: () => {
                notifyError(buildDeleteErrorMessage());
            },
        });
    };

    const loadStoredClips = () => {
        if (!storedUrl) {
            storedItems = [];
            renderStoredList();
            return;
        }

        $.getJSON(storedUrl)
            .done((response) => {
                const items = Array.isArray(response?.data) ? response.data : [];
                storedItems = items.map((item) => ({
                    ...item,
                    rangeLabel: item.rangeLabel || item.range_label || null,
                    categoryName: item.categoryName || item.category_name || null,
                    playUrl: item.play_url || `${playUrlBase}/${item.id}/audio`,
                    deleteUrl: item.delete_url || `${deleteUrlBase}/${item.id}`,
                    translatedText: item.translated_text || null,
                    sourceType: item.sourceType || item.source_type || 'live',
                }))
                    .filter((item) => item.sourceType === 'live')
                    .filter(hasUsefulStoredTranscript);
                renderStoredList();
            })
            .fail((xhr) => {
                storedItems = [];
                renderStoredList();
                const selectedCategory = getCategoryName();
                if (selectedCategory) {
                    notifyError(buildStorageLoadErrorMessage(xhr, selectedCategory));
                }
            });
    };

    const renderQueue = () => {
        updateQueueSummary();

        const items = [...queuedItems].reverse();
        const emptyHidden = queuedItems.length > 0 ? 'hidden' : '';
        const html = items
            .map((item) => {
                const uploadMeta = getUploadStateMeta(item.uploadState || 'waiting');

                return `
                    <article data-queue-item="${item.id}" class="rounded-lg border border-white/10 bg-white/[0.03] p-4 transition">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs uppercase tracking-[0.3em] text-cyan-300">Clip ${item.index}</p>
                                    <span data-upload-state class="inline-flex items-center rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.24em] ${uploadMeta.classes}">
                                        ${uploadMeta.label}
                                    </span>
                                </div>
                                <p class="mt-2 text-lg font-semibold text-white">${item.rangeLabel}</p>
                                <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-800/80">
                                    <div data-item-progress class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-amber-300 transition-[width] duration-150"></div>
                                </div>
                                <p class="mt-2 text-xs uppercase tracking-[0.24em] text-slate-500" data-item-progress-label>${item.rangeLabel}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    data-action="play"
                                    class="group inline-flex cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-sm font-medium text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
                                >
                                    <span data-play-icon="play" class="text-emerald-300">
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                            <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                        </svg>
                                    </span>
                                    <span data-play-icon="pause" class="hidden text-rose-400">
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                            <rect x="6.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                            <rect x="13.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                        </svg>
                                    </span>
                                    <span data-play-label>Play</span>
                                </button>
                                <button
                                    type="button"
                                    data-action="remove"
                                    class="inline-flex cursor-pointer items-center rounded-lg border border-rose-400/20 bg-rose-400/10 px-3 py-2 text-sm font-medium text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </article>
                `;
            })
            .join('');

        $queue.html(`
            <div data-audio-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4 ${emptyHidden}">
                <p class="text-sm text-slate-200">No recordings yet.</p>
            </div>
            ${html}
        `);

        queuedItems.forEach((item) => {
            updateQueueItemProgress(item.id, 0, item.durationMs || 1000);
            setQueueItemUploadState(item.id, item.uploadState || 'waiting');
        });

        if (activeAudioId) {
            setQueueItemPlaybackState(activeAudioId, true);
        }
    };

    const updateActiveProgress = () => {
        if (!isRecording || !segmentStartedAt) {
            return;
        }

        const elapsed = Date.now() - segmentStartedAt;
        const percent = Math.min(100, (elapsed / segmentLengthMs) * 100);
        const clipRange = getCurrentClipRange();

        $progress.css('width', `${percent}%`);
        $progressLabel.text(formatClock(Date.now() - sessionStartedAt));
        $activeName.text('Recording');
        $activeNote.text(clipRange);
    };

    const createQueuedItem = (blob, durationMs) => {
        const url = URL.createObjectURL(blob);
        const clipStartMs = Math.max(0, segmentStartedAt - sessionStartedAt);
        const clipEndMs = clipStartMs + durationMs;
        const categoryName = activeCategoryName || getCategoryName();
        const languageCode = getLanguageCode();
        const speakerSessionId = activeSpeakerSessionId || createSpeakerSessionId();
        const finalizeSession = !isRecording;

        queuedItems.push({
            id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
            index: Math.floor(clipStartMs / segmentLengthMs) + 1,
            url,
            blob,
            durationMs,
            clipStartMs,
            clipEndMs,
            rangeLabel: formatClipRange(clipStartMs, clipEndMs),
            categoryName,
            languageCode,
            speakerSessionId,
            finalizeSession,
            uploadState: 'waiting',
        });

        if (finalizeSession) {
            activeSpeakerSessionId = '';
        }

        renderQueue();
        processUploadQueue();
    };

    const finishSegment = () => {
        if (!recorder) {
            return;
        }

        const currentRecorder = recorder;
        recorder = null;

        if (currentRecorder.state !== 'inactive') {
            currentRecorder.stop();
        }
    };

    const startSegment = () => {
        if (!stream) {
            return;
        }

        activeParts = [];

        if (!sessionStartedAt) {
            sessionStartedAt = Date.now();
        }

        segmentStartedAt = Date.now();
        segmentIndex += 1;

        recorder = new MediaRecorder(stream);
        recorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                activeParts.push(event.data);
            }
        });
        recorder.addEventListener('stop', () => {
            clearTimeout(autoStopTimer);
            autoStopTimer = null;

            const durationMs = Math.max(Date.now() - segmentStartedAt, 1);
            const blob = activeParts.length ? new Blob(activeParts, { type: recorder?.mimeType || 'audio/webm' }) : null;
            activeParts = [];

            if (blob && blob.size > 0) {
                createQueuedItem(blob, durationMs);
            } else if (!isRecording && activeSpeakerSessionId) {
                releaseSpeakerSession(activeSpeakerSessionId);
                activeSpeakerSessionId = '';
            }

            if (isRecording) {
                startSegment();
            } else {
                $progress.css('width', '0%');
                $progressLabel.text('00:00:00');
                $activeName.text('Ready');
                $activeNote.text('');

                if (stream) {
                    stream.getTracks().forEach((track) => track.stop());
                    stream = null;
                }

                sessionStartedAt = 0;
                segmentStartedAt = 0;
                segmentIndex = 0;
            }
        });

        recorder.start();
        setRecordingUi();
        updateActiveProgress();
        progressTimer = progressTimer || window.setInterval(updateActiveProgress, 100);
        autoStopTimer = window.setTimeout(() => {
            if (isRecording) {
                finishSegment();
            }
        }, segmentLengthMs);
    };

    const stopRecording = () => {
        isRecording = false;
        setIdleUi();

        if (autoStopTimer) {
            clearTimeout(autoStopTimer);
            autoStopTimer = null;
        }

        if (progressTimer) {
            clearInterval(progressTimer);
            progressTimer = null;
        }

        if (recorder && recorder.state !== 'inactive') {
            finishSegment();
            return;
        }

        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }

        sessionStartedAt = 0;
        segmentStartedAt = 0;
        segmentIndex = 0;
    };

    const startRecording = async () => {
        if (!supportsRecorder) {
            return;
        }

        const chosenCategory = getCategoryName();
        if (!chosenCategory) {
            syncCategoryUi();
            notifyError('Choose a project name before you start recording.');
            return;
        }

        if (uploadPaused) {
            setSupportMessage('Saving paused');
            notifyError('Saving is paused because the last clip could not be stored.');
            return;
        }

        try {
            activeCategoryName = chosenCategory;
            activeSpeakerSessionId = activeSpeakerSessionId || createSpeakerSessionId();
            setCategoryBadge(activeCategoryName);
            syncRecordingTimeline();

            if (!stream) {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }

            if (!sessionStartedAt) {
                sessionStartedAt = Date.now();
            }

            isRecording = true;
            startSegment();
        } catch (error) {
            setSupportMessage('Microphone blocked');
            notifyError('Microphone access is blocked. Please allow it to record audio.');
            setIdleUi();
            isRecording = false;

            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
            }
        }
    };

    $button.on('click', function () {
        if (isRecording) {
            stopRecording();
        } else {
            startRecording();
        }
    });

    $categoryInput.on('input change', function () {
        if (isRecording) {
            return;
        }

        liveCleanerStatus = 'Waiting';
        syncCategoryUi();
        renderStoredList();
        refreshCategorySuggestions();
    });

    $categoryInput.on('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        if (isRecording) {
            return;
        }

        activeCategoryName = getCategoryName();
        liveCleanerStatus = 'Waiting';
        closeCategorySuggestions();
        syncCategoryUi();
        renderStoredList();
        $categoryInput.trigger('blur');
    });

    $categoryInput.on('focus click', function () {
        if (!isRecording) {
            openCategorySuggestions();
        }
    });

    $categoryInput.on('blur', function () {
        setTimeout(() => {
            closeCategorySuggestions();
        }, 120);
    });

    $categorySuggestions.on('mousedown', '[data-category-pick]', function (event) {
        event.preventDefault();
        const category = String($(this).attr('data-category-pick') || '').trim();
        if (!category) {
            return;
        }

        $categoryInput.val(category);
        activeCategoryName = category;
        liveCleanerStatus = 'Waiting';
        syncCategoryUi();
        renderStoredList();
        closeCategorySuggestions();
    });

    $queue.on('click', '[data-action="play"]', function () {
        const $row = $(this).closest('[data-queue-item]');
        const id = $row.data('queue-item');
        const item = queuedItems.find((entry) => entry.id === id);

        if (item) {
            playQueuedItem(item);
        }
    });

    $queue.on('click', '[data-action="remove"]', function () {
        const $row = $(this).closest('[data-queue-item]');
        const id = $row.data('queue-item');
        removeQueuedItem(id);
    });

    $liveContinueButton.on('click', continueLiveQueue);

    $liveRetryButton.on('click', retryLiveQueue);

    $liveCancelButton.on('click', cancelLiveQueue);

    window.addEventListener('pagehide', () => {
        const sessionIds = new Set([
            activeSpeakerSessionId,
            ...queuedItems.map((item) => item.speakerSessionId),
        ].filter(Boolean));
        sessionIds.forEach((sessionId) => releaseSpeakerSession(sessionId, true));
    });

    $storedList.on('click', '[data-stored-action="play"]', function () {
        const $row = $(this).closest('[data-stored-item]');
        const id = $row.data('stored-item');
        const item = storedItems.find((entry) => String(entry.id) === String(id));

        if (item) {
            playStoredItem(item);
        }
    });

    $storedList.on('click', '[data-stored-action="remove"]', function () {
        const $row = $(this).closest('[data-stored-item]');
        const id = $row.data('stored-item');
        const item = storedItems.find((entry) => String(entry.id) === String(id));

        if (item) {
            deleteStoredItem(item);
        }
    });

    $exportLive.on('click', exportStoredTranscription);
    $logLive.on('click', loadVadLogs);
    $('[data-export-live-mode]').on('change', renderStoredList);
    $('[data-furnish-live]').on('click', furnishStoredTranscription);

    if (!supportsRecorder) {
        setSupportMessage('Unavailable');
        $button
            .attr('disabled', 'disabled')
            .removeClass('hover:scale-[1.01]')
            .addClass('cursor-not-allowed opacity-60');
        $state.text('Unavailable').removeClass('text-cyan-300').addClass('text-rose-300');
        $caption.text('Recorder not supported').removeClass('text-white').addClass('text-rose-50');
        $playIcon.addClass('hidden');
        $stopIcon.addClass('hidden');
        return;
    }

    setIdleUi();
    updateQueueSummary();
    loadStoredClips();
    syncCategoryUi();
});
