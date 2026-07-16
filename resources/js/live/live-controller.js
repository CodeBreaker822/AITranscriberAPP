import { buildDeleteErrorMessage, buildStorageLoadErrorMessage, buildUploadErrorMessage } from '../shared/api-errors.js';
import { escapeHtml, notify, notifyError, readNearbyControlValue, withButtonLoading } from '../shared/dom.js';
import { exportTranscriptRows } from '../shared/export-service.js';
import { formatClipRange, formatClock, formatRelativeClock, slugify, sortByTimeAscending } from '../shared/formatters.js';
import { clampProgressPercent, createPhaseProgress, phaseProgressAverage, phaseProgressSummary } from '../shared/progress.js';
import { buildCleanerBatches, countCleanerBatches } from '../shared/cleaner-batches.js';
import { normalizeStoredItem } from '../shared/normalize.js';
import {
    buildExportRows,
    hasUsefulTranscript,
    isUsefulTranscriptText,
    renderTranscriptText,
} from '../shared/transcripts.js';
import { createLiveWorkflowState } from './live-state.js';

export const initLivePage = (context) => {
    const {
        $body,
        audioChunkLengthMs,
        csrfToken,
        createSpeakerSessionId,
        createTranscriptionProgressId,
        registerWhisperProgress,
        clearWhisperProgress,
        cancelWhisperProgress,
        releaseSpeakerSession,
        requestPolishInstructions,
        getTranscriptionEngine,
        getWhisperModel,
    } = context;

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
    const segmentLengthMs = audioChunkLengthMs;
    const supportsMicrophone = Boolean(navigator.mediaDevices?.getUserMedia);
    const supportsRecorder = Boolean(window.MediaRecorder);
    const liveTimelineStorageKey = 'ai-transcriber-live-timeline-cursors';

    if (csrfToken) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        });
    }

    const liveState = createLiveWorkflowState({
        categoryName: String($body.data('default-category-name') || '').trim(),
    });
    const pause = (milliseconds) => new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });
    const backgroundJobs = window.AITranscriberBackgroundJobs;
    const backgroundAjax = (options, pollOptions = {}) => backgroundJobs.ajax(options, {
        failureMessage: 'Transcript could not be polished.',
        cancelledMessage: 'Transcript polishing was cancelled.',
        ...pollOptions,
    });

    const hasUsefulStoredTranscript = hasUsefulTranscript;

    const getCategoryName = () => String($categoryInput.val() || liveState.activeCategoryName || liveState.categoryName || '').trim();

    const getLanguageCode = () => getTranscriptionEngine() === 'offline'
        ? 'auto'
        : String($languageInput.val() || 'multi').trim() || 'multi';

    const normalizeStoredTranscriptItem = (item) => normalizeStoredItem(item, {
        playUrlBase,
        deleteUrlBase,
        defaultSourceType: 'live',
        emptyAsNull: true,
    });

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

    const exportStoredTranscription = async (event) => {
        const exportMode = readNearbyControlValue(event, '[data-export-live-mode]', 'raw');
        const exportFormat = readNearbyControlValue(event, '[data-export-live-format]', 'txt');
        const useCleaned = exportMode === 'clean';
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

        const rows = buildExportRows(items, useCleaned);

        if (!rows.length) {
            notifyError('No useful transcript text is ready to export yet.');
            return;
        }

        await withButtonLoading($(event?.currentTarget || []), () => exportTranscriptRows({
            rows,
            format: exportFormat,
            filenameBase: `${slugify(selectedCategory)}-${useCleaned ? 'cleaned' : 'raw'}-transcription`,
            title: selectedCategory || 'Live transcription',
            variantLabel: useCleaned ? 'Cleaned' : 'Raw',
        }));
    };

    const renderVadLogs = (logs, categoryName) => {
        liveState.liveShowingVadLogs = true;
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
        $logLive.prop('disabled', false).html(liveState.liveShowingVadLogs ? `
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
        if (liveState.liveShowingVadLogs) {
            liveState.liveShowingVadLogs = false;
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

    const mergeLiveCleanedRows = (categoryName, rows) => {
        const currentPayload = window.liveCleanedTranscriptPayload || {};
        const existing = new Map(
            String(currentPayload.categoryName || '').toLowerCase() === String(categoryName || '').toLowerCase()
                ? (currentPayload.items || []).map((item) => [String(item.audioChunkId), item])
                : [],
        );

        rows.forEach((row) => {
            existing.set(String(row.audio_chunk_id), {
                audioChunkId: row.audio_chunk_id,
                clipStartMs: row.clip_start_ms,
                rangeLabel: row.range_label || '',
                cleanText: row.clean_text || '',
                cleanTimestamps: row.clean_timestamps || [],
            });
        });

        window.liveCleanedTranscriptPayload = {
            categoryName,
            items: Array.from(existing.values())
                .sort((first, second) => Number(first.clipStartMs || 0) - Number(second.clipStartMs || 0)),
        };
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
        liveState.liveCleanerStatus = 'Polishing';
        liveState.liveCleanerCompletedSections = 0;
        window.liveCleanedTranscriptPayload = {
            categoryName,
            items: [],
        };
        renderStoredList();
        updateLiveCleanerProgress();

        try {
            const batches = buildCleanerBatches(getStoredItemsForCategory());

            for (let batchIndex = 0; batchIndex < batches.length; batchIndex += 1) {
                const batch = batches[batchIndex];
                const response = await backgroundAjax({
                    url: furnishUrl,
                    method: 'POST',
                    data: {
                        user_id: defaultUserId,
                        category_name: categoryName,
                        audio_chunk_ids: batch.audioChunkIds,
                        instructions,
                    },
                }, {
                    timeoutMs: 600000,
                    intervalMs: 900,
                });
                const rows = Array.isArray(response?.data) ? response.data : [];

                mergeLiveCleanedRows(categoryName, rows);
                liveState.liveCleanerCompletedSections = batchIndex + 1;
                updateLiveCleanerProgress();
                renderStoredList();

                if (batchIndex < batches.length - 1) {
                    await pause(4000);
                }
            }

            liveState.liveCleanerStatus = 'Complete';
            updateLiveCleanerProgress();
            renderStoredList();
            notify('Transcript polished.');
        } catch (xhr) {
            liveState.liveCleanerStatus = 'Failed';
            updateLiveCleanerProgress();
            notifyError(String(xhr?.responseJSON?.message || 'Transcript could not be polished.'));
        } finally {
            $button.prop('disabled', false).text('Polish');
        }
    };

    const setCategoryBadge = (value) => {
        $currentCategory.text(value || 'Choose project');
    };

    const syncRecordButtonState = () => {
        $button.prop('disabled', false);
        $button.removeClass('cursor-not-allowed opacity-60');
        $button.addClass('hover:scale-[1.01]');
    };

    const syncCategoryUi = () => {
        setCategoryBadge(getCategoryName());

        if (!liveState.isRecording) {
            syncRecordButtonState();
            if (!liveState.uploadPaused) {
                setSupportMessage(!supportsMicrophone || !supportsRecorder
                    ? 'Unavailable'
                    : getCategoryName() ? 'Ready' : 'Choose project');
            }
        }
    };

    const syncCategoryOptions = () => {
        return [...new Set(liveState.storedItems.map((item) => item.categoryName).filter(Boolean))];
    };

    const getStoredItemsForCategory = () => {
        const selectedCategory = getCategoryName().toLowerCase();

        if (!selectedCategory) {
            return [];
        }

        return liveState.storedItems
            .filter((item) => String(item.categoryName || '').toLowerCase() === selectedCategory)
            .filter(hasUsefulStoredTranscript)
            .sort(sortByTimeAscending);
    };

    const getLiveCleanerBatchCount = () => countCleanerBatches(getStoredItemsForCategory());

    const updateLiveCleanerProgress = () => {
        const total = getLiveCleanerBatchCount();
        const selectedCategory = getCategoryName();
        const cleanedPayload = window.liveCleanedTranscriptPayload || {};
        const hasCleanedCategory = selectedCategory !== ''
            && String(cleanedPayload.categoryName || '').toLowerCase() === selectedCategory.toLowerCase()
            && Array.isArray(cleanedPayload.items)
            && cleanedPayload.items.length > 0;

        if (liveState.liveCleanerStatus !== 'Polishing' && liveState.liveCleanerStatus !== 'Failed') {
            liveState.liveCleanerStatus = hasCleanedCategory ? 'Complete' : 'Waiting';
        }

        const complete = liveState.liveCleanerStatus === 'Complete';
        const done = liveState.liveCleanerStatus === 'Polishing'
            ? liveState.liveCleanerCompletedSections
            : complete ? total : 0;
        const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

        $liveCleanerState.text(liveState.liveCleanerStatus);
        $liveCleanerProgressLabel.text(`${done} / ${total} sections`);
        $liveCleanerProgressPercent.text(`${percent}%`);
        $liveCleanerProgressBar.css('width', `${percent}%`);

        if (liveState.liveCleanerStatus === 'Polishing') {
            $liveCleanerProgressNote.text(`Polishing section ${Math.min(done + 1, total)} of ${total}.`);
        } else if (liveState.liveCleanerStatus === 'Complete') {
            $liveCleanerProgressNote.text('The polished transcript is ready for viewing and export.');
        } else if (liveState.liveCleanerStatus === 'Failed') {
            $liveCleanerProgressNote.text('Polishing failed. You can polish the transcript again.');
        } else {
            $liveCleanerProgressNote.text(total > 0
                ? `${total} transcript ${total === 1 ? 'section is' : 'sections are'} ready to polish.`
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
        if (liveState.isRecording) {
            return;
        }

        const latestEndMs = getLatestTimelineEndMsForCategory();
        liveState.sessionStartedAt = latestEndMs > 0 ? Date.now() - latestEndMs : 0;
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
        if (!liveState.sessionStartedAt) {
            return '00:00-01:00';
        }

        const elapsed = Math.max(0, Date.now() - liveState.sessionStartedAt);
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

    const hasLiveWaitingClips = () => liveState.queuedItems.some((item) => item.uploadState === 'waiting');

    const hasLiveRetryableClips = () => liveState.queuedItems.some((item) => item.uploadState === 'error');

    const hasLiveCancelableClips = () => liveState.queuedItems.some((item) => ['waiting', 'sending'].includes(item.uploadState));

    const syncLiveControls = () => {
        $liveContinueButton.prop('disabled', liveState.uploadInFlight || !liveState.uploadPaused || !hasLiveWaitingClips());
        $liveRetryButton.prop('disabled', liveState.uploadInFlight || !hasLiveRetryableClips());
        $liveCancelButton.prop('disabled', !liveState.uploadInFlight && !hasLiveCancelableClips());
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
        syncLiveControls();
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
        setSupportMessage(liveState.uploadInFlight ? 'Sending' : 'Live');
        syncLiveControls();
    };

    const updateQueueSummary = () => {
        $count.text(String(liveState.queuedItems.length));

        if ($empty.length) {
            $empty.toggleClass('hidden', liveState.queuedItems.length > 0);
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
        const item = liveState.queuedItems.find((entry) => entry.id === itemId);
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
        liveState.activeLivePhaseProgress = item.phaseProgress;

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

        if (!liveState.isRecording) {
            $activeName.text('Processing');
            $activeNote.text(summary);
            $progress.css('width', `${percent}%`);
            $progressLabel.text(`${percent}%`);
        }
        setSupportMessage(liveState.isRecording ? `Live - ${label}` : label);
    };

    const stopLivePhaseTimer = () => {
        if (liveState.activeLivePhaseTimer) {
            window.clearInterval(liveState.activeLivePhaseTimer);
            liveState.activeLivePhaseTimer = null;
        }
    };

    const startLivePhaseTimer = (item) => {
        stopLivePhaseTimer();
        liveState.activeLivePhaseProgress = item.phaseProgress || {
            prepare: 0,
            silero: 0,
            transcribe: 0,
            sherpa: 0,
        };
        item.phaseProgress = liveState.activeLivePhaseProgress;

        liveState.activeLivePhaseTimer = window.setInterval(() => {
            if (liveState.activeUploadItemId !== item.id || !liveState.uploadInFlight) {
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

        if (liveState.activeAudioId && liveState.activePlaybackKind === 'stored') {
            setStoredItemPlaybackState(liveState.activeAudioId, true);
        }
    };

    const setQueueItemUploadState = (itemId, state) => {
        const item = liveState.queuedItems.find((entry) => entry.id === itemId);
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
        if (!liveState.activeAudio) {
            return;
        }

        const itemId = liveState.activeAudioId;
        liveState.activeAudio.pause();
        liveState.activeAudio.currentTime = 0;

        if (itemId) {
            if (liveState.activePlaybackKind === 'stored') {
                setStoredItemPlaybackState(itemId, false);
            } else {
                setQueueItemPlaybackState(itemId, false);
            }

            if (resetProgress) {
                const item = liveState.activePlaybackKind === 'stored'
                    ? liveState.storedItems.find((entry) => entry.id === itemId)
                    : liveState.queuedItems.find((entry) => entry.id === itemId);
                if (item) {
                    updateQueueItemProgress(itemId, 0, item.durationMs || 1000);
                }
            }
        }

        liveState.activeAudio = null;
        liveState.activeAudioId = null;
        liveState.activePlaybackKind = null;
    };

    const processUploadQueue = () => {
        if (liveState.uploadPaused || liveState.uploadInFlight || !uploadUrl) {
            syncLiveControls();
            return;
        }

        const nextItem = liveState.queuedItems.find((entry) => entry.uploadState === 'waiting');
        if (!nextItem) {
            if (liveState.isRecording) {
                setSupportMessage('Live');
            } else if (!liveState.uploadPaused) {
                setSupportMessage('Ready');
                $activeName.text('Ready');
                $activeNote.text('');
                $progress.css('width', '0%');
                $progressLabel.text('00:00:00');
            }
            syncLiveControls();
            return;
        }

        liveState.uploadInFlight = true;
        liveState.activeUploadItemId = nextItem.id;
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
        liveState.activeWhisperProgressId = progressId;
        if (progressId) {
            registerWhisperProgress(progressId, (percent) => {
                updateQueueItemTranscriptionProgress(nextItem.id, percent);
            });
        }

        const formData = new FormData();
        formData.append('audio', nextItem.blob, `clip-${nextItem.index}.webm`);
        formData.append('user_id', String(defaultUserId));
        formData.append('category_name', nextItem.categoryName || liveState.activeCategoryName || getCategoryName());
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

        liveState.activeUploadXhr = $.ajax({
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
                    nextItem.categoryName || liveState.activeCategoryName || getCategoryName(),
                    Number(responseData.clip_end_ms || nextItem.clipEndMs || 0),
                );
                setSupportMessage('Saved');
                loadStoredClips();
                removeQueuedItem(nextItem.id, { skipAbort: true });
            },
            error: (_xhr, status) => {
                if (status === 'abort' && liveState.cancelUploadByUser) {
                    liveState.cancelUploadByUser = false;
                    return;
                }

                setQueueItemUploadState(nextItem.id, 'error');
                liveState.uploadPaused = true;
                const errorMessage = buildUploadErrorMessage(_xhr, nextItem);
                setSupportMessage('Save failed');
                notifyError(errorMessage);
                stopRecording();
                syncLiveControls();
            },
            complete: () => {
                stopLivePhaseTimer();
                clearWhisperProgress(liveState.activeWhisperProgressId);
                liveState.activeWhisperProgressId = '';
                liveState.activeLivePhaseProgress = null;
                liveState.uploadInFlight = false;
                liveState.activeUploadXhr = null;
                liveState.activeUploadItemId = null;
                syncLiveControls();

                if (!liveState.uploadPaused) {
                    processUploadQueue();
                }
            },
        });
    };

    const removeQueuedItem = (id, options = {}) => {
        const { skipAbort = false } = options;
        const index = liveState.queuedItems.findIndex((item) => item.id === id);
        if (index === -1) {
            return;
        }

        const [item] = liveState.queuedItems.splice(index, 1);

        if (liveState.activeAudioId === id) {
            stopActiveAudio(false);
        }

        if (!skipAbort && liveState.activeUploadItemId === id && liveState.activeUploadXhr) {
            liveState.cancelUploadByUser = true;
            stopLivePhaseTimer();
            cancelWhisperProgress(liveState.activeWhisperProgressId);
            liveState.activeUploadXhr.abort();
        }

        if (item.url) {
            URL.revokeObjectURL(item.url);
        }

        if (!skipAbort
            && item.finalizeSession
            && item.speakerSessionId
            && !liveState.queuedItems.some((queued) => queued.speakerSessionId === item.speakerSessionId)) {
            releaseSpeakerSession(item.speakerSessionId);
        }

        renderQueue();

        if (!liveState.uploadPaused) {
            processUploadQueue();
        }

        syncLiveControls();
    };

    const cancelLiveQueue = () => {
        const removableItems = liveState.queuedItems.filter((item) => ['waiting', 'sending'].includes(item.uploadState));
        const sessionIds = [...new Set([
            liveState.activeSpeakerSessionId,
            ...liveState.queuedItems.map((item) => item.speakerSessionId),
        ].filter(Boolean))];

        if (!removableItems.length && !liveState.uploadInFlight && !sessionIds.length) {
            return;
        }

        if (liveState.activeUploadXhr) {
            liveState.cancelUploadByUser = true;
            stopLivePhaseTimer();
            cancelWhisperProgress(liveState.activeWhisperProgressId);
            liveState.activeUploadXhr.abort();
        }

        removableItems.forEach((item) => {
            if (liveState.activeAudioId === item.id && liveState.activePlaybackKind === 'live') {
                stopActiveAudio(false);
            }

            if (item.url) {
                URL.revokeObjectURL(item.url);
            }
        });

        liveState.queuedItems = liveState.queuedItems.filter((item) => !['waiting', 'sending'].includes(item.uploadState));
        sessionIds.forEach((sessionId) => releaseSpeakerSession(sessionId));
        liveState.activeSpeakerSessionId = '';
        liveState.uploadInFlight = false;
        liveState.activeUploadItemId = null;
        liveState.activeLivePhaseProgress = null;
        liveState.uploadPaused = hasLiveRetryableClips();
        setSupportMessage(liveState.uploadPaused ? 'Saving paused' : (liveState.isRecording ? 'Live' : 'Ready'));
        renderQueue();
        syncLiveControls();
    };

    const continueLiveQueue = () => {
        if (liveState.uploadInFlight || !hasLiveWaitingClips()) {
            return;
        }

        liveState.uploadPaused = false;
        setSupportMessage('Ready');
        syncLiveControls();
        processUploadQueue();
    };

    const retryLiveQueue = () => {
        if (liveState.uploadInFlight || !hasLiveRetryableClips()) {
            return;
        }

        liveState.queuedItems = liveState.queuedItems.map((item) => item.uploadState === 'error'
            ? {
                ...item,
                uploadState: 'waiting',
            }
            : item);
        liveState.uploadPaused = false;
        renderQueue();
        processUploadQueue();
    };

    const playQueuedItem = (item) => {
        if (liveState.activeAudioId === item.id && liveState.activeAudio) {
            if (liveState.activeAudio.paused) {
                liveState.activeAudio.play();
                setQueueItemPlaybackState(item.id, true);
            } else {
                liveState.activeAudio.pause();
                setQueueItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveAudio(false);

        liveState.activeAudio = new Audio(item.url);
        liveState.activeAudio.preload = 'metadata';
        liveState.activeAudioId = item.id;
        liveState.activePlaybackKind = 'live';

        const syncDuration = () => {
            const durationMs = Number.isFinite(liveState.activeAudio.duration) && liveState.activeAudio.duration > 0
                ? liveState.activeAudio.duration * 1000
                : item.durationMs || 1000;

            item.durationMs = durationMs;
            updateQueueItemProgress(item.id, liveState.activeAudio.currentTime * 1000, durationMs);
        };

        liveState.activeAudio.addEventListener('loadedmetadata', syncDuration);
        liveState.activeAudio.addEventListener('timeupdate', () => {
            const durationMs = Number.isFinite(liveState.activeAudio.duration) && liveState.activeAudio.duration > 0
                ? liveState.activeAudio.duration * 1000
                : item.durationMs || 1000;

            updateQueueItemProgress(item.id, liveState.activeAudio.currentTime * 1000, durationMs);
        });
        liveState.activeAudio.addEventListener('pause', () => {
            if (liveState.activeAudioId === item.id && liveState.activeAudio.paused) {
                setQueueItemPlaybackState(item.id, false);
            }
        });
        liveState.activeAudio.addEventListener('ended', () => {
            const durationMs = Number.isFinite(liveState.activeAudio.duration) && liveState.activeAudio.duration > 0
                ? liveState.activeAudio.duration * 1000
                : item.durationMs || 1000;

            updateQueueItemProgress(item.id, durationMs, durationMs);
            setQueueItemPlaybackState(item.id, false);
            liveState.activeAudio = null;
            liveState.activeAudioId = null;
            liveState.activePlaybackKind = null;
        });

        liveState.activeAudio.play();
        setQueueItemPlaybackState(item.id, true);
    };

    const playStoredItem = (item) => {
        if (liveState.activeAudioId === item.id && liveState.activeAudio) {
            if (liveState.activeAudio.paused) {
                liveState.activeAudio.play();
                setStoredItemPlaybackState(item.id, true);
            } else {
                liveState.activeAudio.pause();
                setStoredItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveAudio(false);

        liveState.activeAudio = new Audio(item.playUrl || `${playUrlBase}/${item.id}/audio`);
        liveState.activeAudio.preload = 'metadata';
        liveState.activeAudioId = item.id;
        liveState.activePlaybackKind = 'stored';

        liveState.activeAudio.addEventListener('ended', () => {
            setStoredItemPlaybackState(item.id, false);
            liveState.activeAudio = null;
            liveState.activeAudioId = null;
            liveState.activePlaybackKind = null;
        });
        liveState.activeAudio.addEventListener('pause', () => {
            if (liveState.activeAudioId === item.id && liveState.activeAudio.paused) {
                setStoredItemPlaybackState(item.id, false);
            }
        });

        liveState.activeAudio.play();
        setStoredItemPlaybackState(item.id, true);
    };

    const deleteStoredItem = (item) => {
        const deleteUrl = item.deleteUrl || `${deleteUrlBase}/${item.id}`;

        if (liveState.activeAudioId === item.id && liveState.activePlaybackKind === 'stored') {
            stopActiveAudio(false);
        }

        $.ajax({
            url: deleteUrl,
            method: 'DELETE',
            success: () => {
                liveState.storedItems = liveState.storedItems.filter((entry) => entry.id !== item.id);
                renderStoredList();
                setSupportMessage(liveState.isRecording ? 'Live' : 'Ready');
            },
            error: () => {
                notifyError(buildDeleteErrorMessage());
            },
        });
    };

    const loadStoredClips = () => {
        if (!storedUrl) {
            liveState.storedItems = [];
            renderStoredList();
            return;
        }

        $.getJSON(storedUrl)
            .done((response) => {
                const items = Array.isArray(response?.data) ? response.data : [];
                liveState.storedItems = items.map(normalizeStoredTranscriptItem)
                    .filter((item) => item.sourceType === 'live')
                    .filter(hasUsefulStoredTranscript);
                renderStoredList();
            })
            .fail((xhr) => {
                liveState.storedItems = [];
                renderStoredList();
                const selectedCategory = getCategoryName();
                if (selectedCategory) {
                    notifyError(buildStorageLoadErrorMessage(xhr, selectedCategory));
                }
            });
    };

    const renderQueue = () => {
        updateQueueSummary();

        const items = [...liveState.queuedItems].reverse();
        const emptyHidden = liveState.queuedItems.length > 0 ? 'hidden' : '';
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

        liveState.queuedItems.forEach((item) => {
            updateQueueItemProgress(item.id, 0, item.durationMs || 1000);
            setQueueItemUploadState(item.id, item.uploadState || 'waiting');
        });

        if (liveState.activeAudioId) {
            setQueueItemPlaybackState(liveState.activeAudioId, true);
        }
    };

    const updateActiveProgress = () => {
        if (!liveState.isRecording || !liveState.segmentStartedAt) {
            return;
        }

        const elapsed = Date.now() - liveState.segmentStartedAt;
        const percent = Math.min(100, (elapsed / segmentLengthMs) * 100);
        const clipRange = getCurrentClipRange();

        $progress.css('width', `${percent}%`);
        $progressLabel.text(formatClock(Date.now() - liveState.sessionStartedAt));
        $activeName.text('Recording');
        $activeNote.text(clipRange);
    };

    const createQueuedItem = (blob, durationMs) => {
        const url = URL.createObjectURL(blob);
        const clipStartMs = Math.max(0, liveState.segmentStartedAt - liveState.sessionStartedAt);
        const clipEndMs = clipStartMs + durationMs;
        const categoryName = liveState.activeCategoryName || getCategoryName();
        const languageCode = getLanguageCode();
        const speakerSessionId = liveState.activeSpeakerSessionId || createSpeakerSessionId();
        const finalizeSession = !liveState.isRecording;

        liveState.queuedItems.push({
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
            liveState.activeSpeakerSessionId = '';
        }

        renderQueue();
        processUploadQueue();
    };

    const finishSegment = () => {
        if (!liveState.recorder) {
            return;
        }

        const currentRecorder = liveState.recorder;
        liveState.recorder = null;

        if (currentRecorder.state !== 'inactive') {
            currentRecorder.stop();
        }
    };

    const startSegment = () => {
        if (!liveState.stream) {
            return;
        }

        liveState.activeParts = [];

        if (!liveState.sessionStartedAt) {
            liveState.sessionStartedAt = Date.now();
        }

        liveState.segmentStartedAt = Date.now();
        liveState.segmentIndex += 1;

        liveState.recorder = new MediaRecorder(liveState.stream);
        liveState.recorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                liveState.activeParts.push(event.data);
            }
        });
        liveState.recorder.addEventListener('stop', () => {
            clearTimeout(liveState.autoStopTimer);
            liveState.autoStopTimer = null;

            const durationMs = Math.max(Date.now() - liveState.segmentStartedAt, 1);
            const blob = liveState.activeParts.length ? new Blob(liveState.activeParts, { type: liveState.recorder?.mimeType || 'audio/webm' }) : null;
            liveState.activeParts = [];

            if (blob && blob.size > 0) {
                createQueuedItem(blob, durationMs);
            } else if (!liveState.isRecording && liveState.activeSpeakerSessionId) {
                releaseSpeakerSession(liveState.activeSpeakerSessionId);
                liveState.activeSpeakerSessionId = '';
            }

            if (liveState.isRecording) {
                startSegment();
            } else {
                $progress.css('width', '0%');
                $progressLabel.text('00:00:00');
                $activeName.text('Ready');
                $activeNote.text('');

                if (liveState.stream) {
                    liveState.stream.getTracks().forEach((track) => track.stop());
                    liveState.stream = null;
                }

                liveState.sessionStartedAt = 0;
                liveState.segmentStartedAt = 0;
                liveState.segmentIndex = 0;
            }
        });

        liveState.recorder.start();
        setRecordingUi();
        updateActiveProgress();
        liveState.progressTimer = liveState.progressTimer || window.setInterval(updateActiveProgress, 100);
        liveState.autoStopTimer = window.setTimeout(() => {
            if (liveState.isRecording) {
                finishSegment();
            }
        }, segmentLengthMs);
    };

    const stopRecording = () => {
        liveState.isRecording = false;
        setIdleUi();

        if (liveState.autoStopTimer) {
            clearTimeout(liveState.autoStopTimer);
            liveState.autoStopTimer = null;
        }

        if (liveState.progressTimer) {
            clearInterval(liveState.progressTimer);
            liveState.progressTimer = null;
        }

        if (liveState.recorder && liveState.recorder.state !== 'inactive') {
            finishSegment();
            return;
        }

        if (liveState.stream) {
            liveState.stream.getTracks().forEach((track) => track.stop());
            liveState.stream = null;
        }

        liveState.sessionStartedAt = 0;
        liveState.segmentStartedAt = 0;
        liveState.segmentIndex = 0;
    };

    const startRecording = async () => {
        if (!supportsMicrophone || !supportsRecorder) {
            setSupportMessage('Unavailable');
            notifyError('Live recording is unavailable in this window. Please reopen the desktop app or use a browser with microphone recording support.');
            return;
        }

        const chosenCategory = getCategoryName();
        if (!chosenCategory) {
            syncCategoryUi();
            notifyError('Choose a project name before you start recording.');
            return;
        }

        if (liveState.uploadPaused) {
            setSupportMessage('Saving paused');
            notifyError('Saving is paused because the last clip could not be stored.');
            return;
        }

        try {
            setSupportMessage('Requesting mic');
            $caption.text('Requesting microphone').removeClass('text-white').addClass('text-rose-50');
            liveState.activeCategoryName = chosenCategory;
            liveState.activeSpeakerSessionId = liveState.activeSpeakerSessionId || createSpeakerSessionId();
            setCategoryBadge(liveState.activeCategoryName);
            syncRecordingTimeline();

            if (!liveState.stream) {
                liveState.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }

            if (!liveState.sessionStartedAt) {
                liveState.sessionStartedAt = Date.now();
            }

            liveState.isRecording = true;
            startSegment();
        } catch (error) {
            setSupportMessage('Microphone blocked');
            notifyError('Microphone access is blocked. Please allow it to record audio.');
            setIdleUi();
            liveState.isRecording = false;

            if (liveState.stream) {
                liveState.stream.getTracks().forEach((track) => track.stop());
                liveState.stream = null;
            }
        }
    };

    $button.on('click', function () {
        if (liveState.isRecording) {
            stopRecording();
        } else {
            startRecording().catch((error) => {
                window.console?.error?.('Live recording could not start.', error);
                liveState.isRecording = false;
                setSupportMessage('Start failed');
                notifyError('Live recording could not start. Please try again.');
                setIdleUi();
            });
        }
    });

    $categoryInput.on('input change', function () {
        if (liveState.isRecording) {
            return;
        }

        liveState.liveCleanerStatus = 'Waiting';
        syncCategoryUi();
        renderStoredList();
        refreshCategorySuggestions();
    });

    $categoryInput.on('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        if (liveState.isRecording) {
            return;
        }

        liveState.activeCategoryName = getCategoryName();
        liveState.liveCleanerStatus = 'Waiting';
        closeCategorySuggestions();
        syncCategoryUi();
        renderStoredList();
        $categoryInput.trigger('blur');
    });

    $categoryInput.on('focus click', function () {
        if (!liveState.isRecording) {
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
        liveState.activeCategoryName = category;
        liveState.liveCleanerStatus = 'Waiting';
        syncCategoryUi();
        renderStoredList();
        closeCategorySuggestions();
    });

    $queue.on('click', '[data-action="play"]', function () {
        const $row = $(this).closest('[data-queue-item]');
        const id = $row.data('queue-item');
        const item = liveState.queuedItems.find((entry) => entry.id === id);

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
            liveState.activeSpeakerSessionId,
            ...liveState.queuedItems.map((item) => item.speakerSessionId),
        ].filter(Boolean));
        sessionIds.forEach((sessionId) => releaseSpeakerSession(sessionId, true));
    });

    $storedList.on('click', '[data-stored-action="play"]', function () {
        const $row = $(this).closest('[data-stored-item]');
        const id = $row.data('stored-item');
        const item = liveState.storedItems.find((entry) => String(entry.id) === String(id));

        if (item) {
            playStoredItem(item);
        }
    });

    $storedList.on('click', '[data-stored-action="remove"]', function () {
        const $row = $(this).closest('[data-stored-item]');
        const id = $row.data('stored-item');
        const item = liveState.storedItems.find((entry) => String(entry.id) === String(id));

        if (item) {
            deleteStoredItem(item);
        }
    });

    $exportLive.on('click', exportStoredTranscription);
    $logLive.on('click', loadVadLogs);
    $('[data-export-live-mode]').on('change', renderStoredList);
    $('[data-furnish-live]').on('click', furnishStoredTranscription);

    setIdleUi();
    updateQueueSummary();
    loadStoredClips();
    syncCategoryUi();

    if (!supportsMicrophone || !supportsRecorder) {
        setSupportMessage('Unavailable');
        $state.text('Unavailable').removeClass('text-cyan-300').addClass('text-rose-300');
        $caption.text('Click for details').removeClass('text-white').addClass('text-rose-50');
    }
};
