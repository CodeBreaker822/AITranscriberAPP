import { buildUploadSessionErrorMessage } from '../shared/api-errors.js';
import { escapeHtml, notify, notifyError, readNearbyControlValue, withButtonLoading } from '../shared/dom.js';
import { exportTranscriptRows } from '../shared/export-service.js';
import { formatBytes, formatClipRange, formatClock, slugify, sortByTimeAscending } from '../shared/formatters.js';
import { clampProgressPercent, createPhaseProgress, phaseProgressAverage, phaseProgressSummary } from '../shared/progress.js';
import { buildCleanerBatches, countCleanerBatches } from '../shared/cleaner-batches.js';
import { normalizeStoredItem } from '../shared/normalize.js';
import {
    buildExportRows,
    hasUsefulTranscript,
    isUsefulTranscriptText,
    renderTranscriptText,
} from '../shared/transcripts.js';
import { createUploadWorkflowState } from './upload-state.js';

export const initUploadPage = (context) => {
    const {
        $body,
        audioChunkSeconds,
        audioChunkLengthMs,
        audioChunkDurationLabel,
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
    const uploadFrontendVersion = 'upload-flow-v4-queue-parity';
    const uploadStateStorageKey = 'ai-transcriber-upload-session';
    const pause = (milliseconds) => new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });
    const tauriInvoke = () => window.__TAURI__?.core?.invoke;
    const backgroundJobs = window.AITranscriberBackgroundJobs;
    const backgroundAjax = (options, pollOptions = {}) => backgroundJobs.ajax(options, {
        failureMessage: 'Audio processing failed.',
        cancelledMessage: 'Audio processing was cancelled.',
        ...pollOptions,
    });

    const cancelActiveBackgroundJobs = () => {
        backgroundJobs.cancelAll();
    };

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
    const audioChunkStatusUrl = String($body.attr('data-audio-chunk-status-url') || '');
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

    const uploadState = createUploadWorkflowState();

    const getChunkLengthMs = () => audioChunkLengthMs;
    $chunkSize.val(String(audioChunkSeconds));

    const getUploadPrepareConcurrency = () => Math.max(1, Math.min(
        uploadState.preparedSections.length || 1,
        Number($body.attr('data-resource-cpu-threads') || window.navigator?.hardwareConcurrency || 1) || 1,
    ));

    const hasUsefulUploadTranscript = hasUsefulTranscript;

    const getUploadCategory = () => String($categoryInput.val() || '').trim();

    const getUploadLanguageCode = () => getTranscriptionEngine() === 'offline'
        ? 'auto'
        : String($languageInput.val() || 'multi').trim() || 'multi';

    const hasCleanedUploadTranscriptForCategory = (categoryName) => (
        uploadState.uploadCleanedCategoryName
        && String(uploadState.uploadCleanedCategoryName).toLowerCase() === String(categoryName || '').toLowerCase()
    );

    const syncTranscriptCategory = () => {
        $transcriptCategory.text(getUploadCategory() || uploadState.selectedFile?.name || 'Upload audio');
    };

    const saveUploadState = () => {
        if (!uploadState.currentSessionId) {
            return;
        }

        try {
            window.localStorage.setItem(uploadStateStorageKey, JSON.stringify({
                sessionId: uploadState.currentSessionId,
                categoryName: uploadState.currentCategoryName || getUploadCategory(),
                durationMs: uploadState.selectedDurationMs,
                sections: uploadState.preparedSections,
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

    const hasRetryableSections = () => uploadState.preparedSections.some((section) => ['Failed', 'Cancelled'].includes(section.status));

    const hasUnfinishedSections = () => uploadState.preparedSections.some((section) => section.status !== 'Complete');

    const hasUploadProgress = () => uploadState.preparedSections.length > 0;

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
        uploadState.activeUploadPhaseProgress = null;
        uploadState.activeUploadPhaseDefinitions = [];
        $progressPercent.text('0%');
        $progress.css('width', '0%');
        $status.removeAttr('title');
    };
    const beginUploadPhaseProgress = (label = 'Processing') => {
        uploadState.activeUploadPhaseDefinitions = getUploadPhaseDefinitions();
        uploadState.activeUploadPhaseProgress = createPhaseProgress(uploadState.activeUploadPhaseDefinitions);
        $status.text(label);
        $status.attr('title', phaseProgressSummary(uploadState.activeUploadPhaseProgress, uploadState.activeUploadPhaseDefinitions));
        $progressPercent.text('0%');
        $progress.css('width', '0%');
    };
    const renderUploadPhaseProgress = (label = '') => {
        if (!uploadState.activeUploadPhaseProgress || !uploadState.activeUploadPhaseDefinitions.length) {
            return false;
        }

        const percent = phaseProgressAverage(uploadState.activeUploadPhaseProgress, uploadState.activeUploadPhaseDefinitions);

        $progressPercent.text(`${percent}%`);
        $progress.css('width', `${percent}%`);
        $status.attr('title', phaseProgressSummary(uploadState.activeUploadPhaseProgress, uploadState.activeUploadPhaseDefinitions));
        if (label) {
            $status.text(label);
        }

        return true;
    };
    const updateUploadPhaseProgress = (updates = {}, label = '') => {
        if (!uploadState.activeUploadPhaseProgress || !uploadState.activeUploadPhaseDefinitions.length) {
            beginUploadPhaseProgress(label || 'Processing');
        }

        uploadState.activeUploadPhaseProgress = {
            ...uploadState.activeUploadPhaseProgress,
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
        uploadState.sherpaProgressActive = false;
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

        uploadState.sherpaProgressActive = safeTotal > 0 && percent < 100;
        $sherpaProgress.toggleClass('hidden', safeTotal === 0);
        $sherpaStatus.text(failed > 0
            ? `${status} ${safeDone} / ${safeTotal}, ${failed} failed`
            : `${status} ${safeDone} / ${safeTotal}`);
        $sherpaPercent.text(`${percent}%`);
        $sherpaBar.css('width', `${percent}%`);
    };

    const resetUploadProgress = (status = 'Ready', options = {}) => {
        const { keepCancelFlag = false, keepSherpaProgress = false } = options;
        const displayStatus = status === 'Ready' && (uploadState.cancelRequested || uploadState.uploadCancelling)
            ? 'Cancelled'
            : status;

        stopSectionProgress();
        uploadState.preparedSections = [];
        uploadState.currentSessionId = '';
        uploadState.currentCategoryName = '';
        uploadState.pauseRequested = false;
        uploadState.uploadInFlight = false;
        uploadState.uploadCancelling = false;
        uploadState.activePrepareXhrs.forEach((xhr) => xhr?.abort?.());
        uploadState.activePrepareXhrs = [];

        if (!keepCancelFlag) {
            uploadState.cancelRequested = false;
        }

        clearUploadState();
        if (!keepSherpaProgress) {
            uploadState.activeDiarizationMonitorGeneration += 1;
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
        const hasSession = Boolean(uploadState.currentSessionId);

        $queueButton.prop('disabled', uploadState.uploadInFlight || uploadState.uploadCancelling || !uploadState.selectedFile || hasSession);
        $pauseButton.prop('disabled', uploadState.uploadCancelling || !uploadState.uploadInFlight || !hasSession || uploadState.pauseRequested);
        $continueButton.prop('disabled', uploadState.uploadInFlight || uploadState.uploadCancelling || !hasSession || !hasUnfinishedSections());
        $retryButton.prop('disabled', uploadState.uploadInFlight || uploadState.uploadCancelling || !hasSession || !hasRetryableSections());
        $cancelButton.prop('disabled', uploadState.uploadCancelling || (!uploadState.uploadInFlight && !hasUploadProgress()));
        $categoryInput.prop('disabled', uploadState.uploadInFlight && !uploadState.uploadCancelling);
        $languageInput.prop('disabled', uploadState.uploadInFlight && !uploadState.uploadCancelling);
    };

    const showUploadCancellingState = () => {
        uploadState.uploadCancelling = true;
        uploadState.uploadInFlight = false;
        $status.text('Cancelling');
        $status.attr('title', 'Cancelling active upload work and clearing pending sections.');
        uploadState.preparedSections = uploadState.preparedSections.map((section) => section.status === 'Complete'
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

    const normalizeUploadStoredItem = (item) => normalizeStoredItem(item);

    const getUploadStoredItemsForCategory = () => {
        const selectedCategory = getUploadCategory().toLowerCase();

        if (!selectedCategory) {
            return [];
        }

        return uploadState.uploadStoredItems
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

        uploadState.uploadStoredItems = [
            ...uploadState.uploadStoredItems.filter((entry) => String(entry.id) !== String(normalized.id)),
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

    const monitorQueuedDiarization = (audioChunkIds, attemptsRemaining = 450, generation = uploadState.activeDiarizationMonitorGeneration) => {
        if (generation !== uploadState.activeDiarizationMonitorGeneration) {
            return;
        }

        const pendingIds = [...new Set(audioChunkIds.map((id) => Number(id)).filter((id) => id > 0))];

        if (!audioChunkStatusUrl || !pendingIds.length || attemptsRemaining <= 0) {
            if (pendingIds.length && attemptsRemaining <= 0) {
                renderSherpaProgress(0, pendingIds.length, { status: 'Timed out' });
            }
            return;
        }

        if ($sherpaProgress.hasClass('hidden')) {
            renderSherpaProgress(0, pendingIds.length, { status: 'Diarization in progress' });
        }

        window.setTimeout(() => {
            if (generation !== uploadState.activeDiarizationMonitorGeneration) {
                return;
            }

            $.getJSON(audioChunkStatusUrl, { ids: pendingIds })
                .done((response) => {
                    if (generation !== uploadState.activeDiarizationMonitorGeneration) {
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
                    if (generation !== uploadState.activeDiarizationMonitorGeneration) {
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

        if (!uniqueIds.length || !audioChunkStatusUrl) {
            return;
        }

        uploadState.activeDiarizationMonitorGeneration += 1;
        monitorQueuedDiarization(uniqueIds, 450, uploadState.activeDiarizationMonitorGeneration);
    };

    const syncUploadCategoriesFromStoredItems = () => {
        uploadState.uploadCategories = [...new Set(uploadState.uploadStoredItems
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
        if (!uploadState.activeUploadAudio) {
            return;
        }

        const itemId = uploadState.activeUploadAudioId;
        uploadState.activeUploadAudio.pause();
        uploadState.activeUploadAudio.currentTime = 0;

        if (itemId) {
            setUploadStoredItemPlaybackState(itemId, false);
        }

        uploadState.activeUploadAudio = null;
        uploadState.activeUploadAudioId = null;
    };

    const playUploadStoredItem = (item) => {
        if (uploadState.activeUploadAudioId === item.id && uploadState.activeUploadAudio) {
            if (uploadState.activeUploadAudio.paused) {
                uploadState.activeUploadAudio.play();
                setUploadStoredItemPlaybackState(item.id, true);
            } else {
                uploadState.activeUploadAudio.pause();
                setUploadStoredItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveUploadAudio();

        uploadState.activeUploadAudio = new Audio(item.playUrl);
        uploadState.activeUploadAudio.preload = 'metadata';
        uploadState.activeUploadAudioId = item.id;

        uploadState.activeUploadAudio.addEventListener('ended', () => {
            setUploadStoredItemPlaybackState(item.id, false);
            uploadState.activeUploadAudio = null;
            uploadState.activeUploadAudioId = null;
        });
        uploadState.activeUploadAudio.addEventListener('pause', () => {
            if (uploadState.activeUploadAudioId === item.id && uploadState.activeUploadAudio?.paused) {
                setUploadStoredItemPlaybackState(item.id, false);
            }
        });

        uploadState.activeUploadAudio.play();
        setUploadStoredItemPlaybackState(item.id, true);
    };

    const deleteUploadStoredItem = (item) => {
        if (!item.deleteUrl) {
            notifyError('Could not remove this clip right now.');
            return;
        }

        if (uploadState.activeUploadAudioId === item.id) {
            stopActiveUploadAudio();
        }

        $.ajax({
            url: item.deleteUrl,
            method: 'DELETE',
            success: () => {
                uploadState.uploadStoredItems = uploadState.uploadStoredItems.filter((entry) => String(entry.id) !== String(item.id));
                uploadState.cleanedSections = uploadState.cleanedSections.filter((section) => String(section.audioChunkId) !== String(item.id));
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

    const getCleanerBatchCount = () => countCleanerBatches(getUploadStoredItemsForCategory());

    const getCleanerBatches = () => buildCleanerBatches(getUploadStoredItemsForCategory());

    const updateCleanerProgress = () => {
        const total = getCleanerBatchCount();
        const cleanedCount = uploadState.cleanedSections.length;
        const rawCount = getUploadStoredItemsForCategory().length;
        const hasCleanedCategory = hasCleanedUploadTranscriptForCategory(getUploadCategory());
        const isComplete = total > 0 && hasCleanedCategory && cleanedCount >= rawCount;
        const done = isComplete ? total : 0;
        const activeDone = uploadState.cleanerStatus === 'Polishing' ? uploadState.cleanerCompletedBatches : done;
        const percent = total > 0 ? Math.min(100, Math.round((activeDone / total) * 100)) : 0;

        if ((total === 0 || !hasCleanedCategory) && uploadState.cleanerStatus !== 'Polishing') {
            uploadState.cleanerStatus = 'Waiting';
        }

        if (isComplete && uploadState.cleanerStatus !== 'Polishing') {
            uploadState.cleanerStatus = 'Complete';
        }

        $cleanerState.text(uploadState.cleanerStatus);
        $cleanerProgressLabel.text(`${uploadState.cleanerStatus === 'Polishing' ? uploadState.cleanerCompletedBatches : activeDone} / ${total} sections`);
        $cleanerProgressPercent.text(`${percent}%`);
        $cleanerProgressBar.css('width', `${percent}%`);

        if (uploadState.cleanerStatus === 'Polishing') {
            $cleanerProgressNote.text(`Polishing section ${Math.min(uploadState.cleanerCompletedBatches + 1, total)} of ${total}.`);
            return;
        }

        if (uploadState.cleanerStatus === 'Complete') {
            $cleanerProgressNote.text(`${cleanedCount} cleaned ${cleanedCount === 1 ? 'section' : 'sections'} ready for export.`);
            return;
        }

        if (uploadState.cleanerStatus === 'Failed') {
            $cleanerProgressNote.text('Polishing failed. You can polish the transcript again.');
            return;
        }

        $cleanerProgressNote.text(total > 0
            ? `${total} transcript ${total === 1 ? 'section is' : 'sections are'} ready to polish.`
            : 'The polished transcript will be prepared after raw transcription is ready.');
    };

    const renderSectionProgress = () => {
        const total = uploadState.preparedSections.length;
        const complete = uploadState.preparedSections.filter((section) => section.status === 'Complete').length;
        const nextIndex = uploadState.preparedSections.findIndex((section) => section.status !== 'Complete');
        const position = uploadState.activeSectionPosition || (nextIndex >= 0 ? nextIndex + 1 : total);
        const percent = total === 0
            ? 0
            : uploadState.activeSectionPosition > 0
                ? uploadState.activeSectionProgress
                : complete === total
                    ? 100
                    : 0;
        const activeUnits = uploadState.activeBatchPositions.length > 0
            ? uploadState.activeBatchPositions.length
            : (uploadState.activeSectionPosition > 0 ? 1 : 0);
        const transcribePercent = total === 0
            ? 0
            : Math.round(((complete + (activeUnits * (percent / 100))) / total) * 100);
        const progressIndex = uploadState.activeBatchPositions.length > 0
            ? Math.min(
                uploadState.activeBatchPositions.length - 1,
                Math.floor((Math.max(0, percent) / 100) * uploadState.activeBatchPositions.length),
            )
            : -1;
        const activeLabel = progressIndex >= 0
            ? `Processing ${uploadState.activeBatchPositions[progressIndex]} of ${total}`
            : '';

        if (uploadState.activeUploadPhaseProgress) {
            uploadState.activeUploadPhaseProgress = {
                ...uploadState.activeUploadPhaseProgress,
                transcribe: clampProgressPercent(transcribePercent),
            };
            renderUploadPhaseProgress(activeLabel);
            return;
        }

        $progressPercent.text(`${percent}%`);
        $progress.css('width', `${percent}%`);

        if (uploadState.activeBatchPositions.length > 0) {
            $status.text(activeLabel);
        }
    };

    const stopSectionProgress = () => {
        if (uploadState.activeSectionProgressTimer) {
            window.clearInterval(uploadState.activeSectionProgressTimer);
            uploadState.activeSectionProgressTimer = null;
        }

        clearWhisperProgress(uploadState.activeSectionProgressId);
        uploadState.activeSectionProgressId = '';

        uploadState.activeSectionPosition = 0;
        uploadState.activeSectionProgress = 0;
        uploadState.activeBatchPositions = [];
    };

    const beginSectionProgress = (position, progressId = '', offline = false) => {
        stopSectionProgress();
        uploadState.activeSectionPosition = position;
        uploadState.activeSectionProgress = 0;
        uploadState.activeSectionProgressId = progressId;
        renderSectionProgress();

        if (offline && progressId) {
            $status.text('Preparing offline audio');
            registerWhisperProgress(progressId, (whisperPercent) => {
                if (uploadState.activeSectionProgressTimer) {
                    window.clearInterval(uploadState.activeSectionProgressTimer);
                    uploadState.activeSectionProgressTimer = null;
                }

                uploadState.activeSectionProgress = Math.round(whisperPercent);
                $status.text(whisperPercent >= 100
                    ? 'Separating speakers'
                    : `Whisper ${Math.round(whisperPercent)}%`);
                renderSectionProgress();
            });
            uploadState.activeSectionProgressTimer = window.setInterval(() => {
                uploadState.activeSectionProgress = Math.min(8, uploadState.activeSectionProgress + 1);
                renderSectionProgress();
            }, 500);
            return;
        }

        uploadState.activeSectionProgressTimer = window.setInterval(() => {
            const remaining = 98 - uploadState.activeSectionProgress;
            uploadState.activeSectionProgress = Math.min(98, uploadState.activeSectionProgress + Math.max(1, Math.ceil(remaining * 0.08)));
            renderSectionProgress();
        }, 350);
    };

    const completeSectionProgress = async () => {
        if (uploadState.activeSectionProgressTimer) {
            window.clearInterval(uploadState.activeSectionProgressTimer);
            uploadState.activeSectionProgressTimer = null;
        }

        clearWhisperProgress(uploadState.activeSectionProgressId);
        uploadState.activeSectionProgressId = '';

        uploadState.activeSectionProgress = 100;
        renderSectionProgress();
        await pause(150);
        uploadState.activeSectionPosition = 0;
        uploadState.activeSectionProgress = 0;
    };

    const updateProgress = () => {
        const visible = uploadState.preparedSections.filter((section) => section.status !== 'Complete').length;

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
                ? uploadState.cleanedSections.filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || ''))
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

        if (uploadState.activeUploadAudioId) {
            setUploadStoredItemPlaybackState(uploadState.activeUploadAudioId, true);
        }
    };

    const exportTranscript = async (event) => {
        const selectedCategory = getUploadCategory();
        const exportMode = readNearbyControlValue(event, '[data-export-upload-mode]', $exportMode.val() || 'raw');
        const exportFormat = readNearbyControlValue(event, '[data-export-upload-format]', 'txt');
        const useCleaned = exportMode === 'clean';
        const completed = useCleaned
            ? (hasCleanedUploadTranscriptForCategory(selectedCategory)
                ? uploadState.cleanedSections.filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || ''))
                : [])
            : getUploadStoredItemsForCategory();

        if (!completed.length) {
            notifyError(useCleaned
                ? 'Polish the transcript before exporting the cleaned version.'
                : 'No transcription is ready to export yet.');
            return;
        }

        const rows = buildExportRows(completed, useCleaned);

        if (!rows.length) {
            notifyError('No useful transcript text is ready to export yet.');
            return;
        }

        await withButtonLoading($(event?.currentTarget || []), () => exportTranscriptRows({
            rows,
            format: exportFormat,
            filenameBase: `${slugify(selectedCategory || uploadState.selectedFile?.name)}-${useCleaned ? 'cleaned' : 'raw'}-transcription`,
            title: selectedCategory || uploadState.selectedFile?.name || 'Upload audio',
            variantLabel: useCleaned ? 'Cleaned' : 'Raw',
        }));
    };

    const renderVadLogs = (logs, categoryName) => {
        uploadState.uploadShowingVadLogs = true;
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
        $logButton.prop('disabled', false).html(uploadState.uploadShowingVadLogs ? `
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
        if (uploadState.uploadShowingVadLogs) {
            uploadState.uploadShowingVadLogs = false;
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
        const existing = new Map(uploadState.cleanedSections.map((section) => [String(section.audioChunkId), section]));

        rows.forEach((row) => {
            existing.set(String(row.audio_chunk_id), {
                audioChunkId: row.audio_chunk_id,
                rangeLabel: row.range_label || '',
                cleanText: row.clean_text || '',
                cleanTimestamps: row.clean_timestamps || [],
            });
        });

        uploadState.cleanedSections = Array.from(existing.values())
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

        if (uploadState.furnishInFlight || !furnishUrl) {
            return;
        }

        const instructions = await requestPolishInstructions();

        if (!instructions) {
            return;
        }

        uploadState.furnishInFlight = true;
        uploadState.cleanerStatus = 'Polishing';
        uploadState.cleanerCompletedBatches = 0;
        uploadState.uploadCleanedCategoryName = categoryName;
        $furnishButton.prop('disabled', true).text('Polishing');
        renderTranscript();
        updateCleanerProgress();

        try {
            const batches = getCleanerBatches();
            const total = batches.length;

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

                mergeCleanedRows(rows);
                uploadState.cleanerCompletedBatches = batchIndex + 1;
                updateCleanerProgress();
                renderTranscript();

                if (batchIndex < total - 1) {
                    await pause(4000);
                }
            }

            uploadState.cleanerStatus = 'Complete';
            updateCleanerProgress();
            renderTranscript();
            notify('Transcript polished.');
        } catch (xhr) {
            uploadState.cleanerStatus = 'Failed';
            updateCleanerProgress();
            notifyError(String(xhr?.responseJSON?.message || 'Transcript could not be polished.'));
        } finally {
            uploadState.furnishInFlight = false;
            $furnishButton.prop('disabled', false).text('Polish');
        }
    };

    const renderQueue = () => {
        const visibleSections = uploadState.preparedSections.filter((section) => section.status !== 'Complete');

        if (!visibleSections.length) {
            const message = uploadState.preparedSections.some((section) => section.status === 'Complete')
                ? 'No pending recordings yet.'
                : uploadState.selectedFile ? 'No pending recordings yet.' : undefined;
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
        if (!uploadState.selectedFile) {
            return [];
        }

        const chunkLengthMs = getChunkLengthMs();
        const durationMs = uploadState.selectedDurationMs > 0 ? uploadState.selectedDurationMs : chunkLengthMs;
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
        const durationMs = uploadState.selectedDurationMs > 0 ? uploadState.selectedDurationMs : 0;

        $duration.text(durationMs > 0 ? formatClock(durationMs) : '--:--');
        $status.text(uploadState.selectedFile ? 'Ready' : 'Ready');
        $queueButton.prop('disabled', uploadState.uploadInFlight || !uploadState.selectedFile);
        syncUploadControls();
    };

    const syncUploadCategoryOptions = () => [...new Set(uploadState.uploadCategories.filter(Boolean))];

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

        if (!category || uploadState.uploadCategories.some((item) => item.toLowerCase() === category.toLowerCase())) {
            return;
        }

        uploadState.uploadCategories = [...uploadState.uploadCategories, category].sort((first, second) => first.localeCompare(second));
        refreshUploadCategorySuggestions();
    };

    const loadUploadCategories = () => {
        if (!storedUrl) {
            uploadState.uploadCategories = [];
            uploadState.uploadStoredItems = [];
            renderTranscript();
            return;
        }

        $.getJSON(storedUrl)
            .done((response) => {
                const items = Array.isArray(response?.data) ? response.data : [];
                uploadState.uploadStoredItems = items
                    .map(normalizeUploadStoredItem)
                    .filter((item) => item.sourceType === 'upload')
                    .filter(hasUsefulUploadTranscript);
                syncUploadCategoriesFromStoredItems();
                renderTranscript();
                refreshUploadCategorySuggestions();
            })
            .fail(() => {
                uploadState.uploadCategories = [];
                uploadState.uploadStoredItems = [];
                renderTranscript();
                refreshUploadCategorySuggestions();
            });
    };

    const setProcessingState = (isProcessing) => {
        uploadState.uploadInFlight = isProcessing;
        if (isProcessing) {
            uploadState.uploadCancelling = false;
        }
        if (isProcessing) {
            resetSherpaProgress();
        }
        $status.text(isProcessing ? 'Processing' : uploadState.selectedFile ? 'Ready' : 'Ready');
        syncUploadControls();
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

        const candidates = uploadState.preparedSections
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
                uploadState.preparedSections[index] = {
                    ...uploadState.preparedSections[index],
                    status: 'Preparing',
                    preparedMeta: 'Preparing audio',
                };
            });
            renderQueue();

            try {
                for (let offset = 0; offset < candidates.length; offset += concurrency) {
                    if (uploadState.cancelRequested) {
                        return false;
                    }

                    const batch = candidates.slice(offset, offset + concurrency);
                    const xhr = backgroundAjax({
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
                    }, {
                        cancelled: () => uploadState.cancelRequested,
                    });
                    uploadState.activePrepareXhrs.push(xhr);

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
                            uploadState.preparedSections[index] = {
                                ...uploadState.preparedSections[index],
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
                        uploadState.activePrepareXhrs = uploadState.activePrepareXhrs.filter((item) => item !== xhr);
                    }
                }
                return true;
            } catch (xhr) {
                if (uploadState.cancelRequested || xhr?.statusText === 'abort') {
                    return false;
                }

                candidates.forEach(({ index }) => {
                    if (uploadState.preparedSections[index]?.status === 'Preparing') {
                        uploadState.preparedSections[index] = {
                            ...uploadState.preparedSections[index],
                            status: 'Failed',
                            preparedMeta: 'Audio preparation failed',
                        };
                    }
                });
                $status.text('Failed');
                renderQueue();
                notifyError(buildUploadSessionErrorMessage(xhr));
                return false;
            }
        }

        const runNext = async () => {
            while (!failed && !uploadState.cancelRequested && cursor < candidates.length) {
                const { section, index } = candidates[cursor];
                cursor += 1;

                uploadState.preparedSections[index] = {
                    ...uploadState.preparedSections[index],
                    status: 'Preparing',
                    preparedMeta: 'Preparing audio',
                };
                renderQueue();

                const xhr = backgroundAjax({
                    url: uploadPrepareUrl,
                    method: 'POST',
                    data: buildUploadSectionFormData(sessionId, categoryName, section, {
                        speaker_session_id: getTranscriptionEngine() === 'online' ? sessionId : '',
                    }),
                    processData: false,
                    contentType: false,
                }, {
                    cancelled: () => uploadState.cancelRequested,
                });
                uploadState.activePrepareXhrs.push(xhr);

                try {
                    const response = await xhr;
                    const data = response?.data || {};
                    const skipped = Boolean(data.skipped || data.prepared_skipped);
                    const audioChunkId = Number(data.audio_chunk_id || 0);
                    completed += 1;
                    uploadState.preparedSections[index] = {
                        ...uploadState.preparedSections[index],
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
                    if (uploadState.cancelRequested || xhr?.statusText === 'abort') {
                        failed = true;
                        return;
                    }

                    failed = true;
                    uploadState.preparedSections[index] = {
                        ...uploadState.preparedSections[index],
                        status: 'Failed',
                        preparedMeta: 'Audio preparation failed',
                    };
                    $status.text('Failed');
                    renderQueue();
                    notifyError(buildUploadSessionErrorMessage(xhr));
                } finally {
                    uploadState.activePrepareXhrs = uploadState.activePrepareXhrs.filter((item) => item !== xhr);
                }
            }
        };

        await Promise.all(Array.from({ length: concurrency }, () => runNext()));

        return !failed && !uploadState.cancelRequested;
    };

    const startPreparedUploadDiarization = async (sessionId) => {
        if (getTranscriptionEngine() !== 'online' || !uploadDiarizeUrl) {
            return [];
        }

        const sections = uploadState.preparedSections
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
            uploadState.preparedSections = uploadState.preparedSections.map((section) => {
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
        uploadState.cancelRequested = false;
        uploadState.pauseRequested = false;
        const queuedDiarizationIds = uploadState.preparedSections
            .filter((section) => section.diarizationQueued && Number(section.audioChunkId || 0) > 0)
            .map((section) => Number(section.audioChunkId));

        if (!audioChunkUrl) {
            uploadState.preparedSections = uploadState.preparedSections.map((section) => ({
                ...section,
                status: 'Failed',
            }));
            $status.text('Failed');
            renderQueue();
            notifyError('Audio section endpoint is missing. Refresh the page and try again.');
            uploadState.uploadInFlight = false;
            syncUploadControls();
            return;
        }

        if (!uploadState.activeUploadPhaseProgress) {
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

            if (uploadState.cancelRequested) {
                uploadState.activeSectionXhr = null;
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
                uploadState.preparedSections[index] = {
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
            uploadState.activeSectionXhr = null;
            renderQueue();
            renderTranscript();
            saveUploadState();
            return true;
        };

        if (audioChunkBatchUrl && getTranscriptionEngine() === 'online') {
            let index = 0;

            while (index < uploadState.preparedSections.length) {
                if (uploadState.cancelRequested || uploadState.pauseRequested) {
                    break;
                }

                if (uploadState.preparedSections[index].status === 'Complete') {
                    index += 1;
                    continue;
                }

                const batch = [];
                let batchDurationMs = 0;

                while (index < uploadState.preparedSections.length && batch.length < maxTranscribeBatchClips) {
                    const section = uploadState.preparedSections[index];

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
                        uploadState.uploadInFlight = false;
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
                    uploadState.preparedSections[sectionIndex].status = 'Processing';
                });

                const positions = batch.map(({ index: sectionIndex }) => sectionIndex + 1);
                const firstPosition = positions[0];
                const lastPosition = positions[positions.length - 1];
                $status.text(`Processing ${firstPosition} of ${uploadState.preparedSections.length}`);

                beginSectionProgress(firstPosition, '', false);
                uploadState.activeBatchPositions = positions;
                renderSectionProgress();
                renderQueue();

                try {
                    uploadState.activeSectionXhr = backgroundAjax({
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
                            finalize_session: lastPosition === uploadState.preparedSections.length ? 1 : 0,
                            sections: batch.map(({ section }) => commonSectionPayload(section)),
                        }),
                        contentType: 'application/json',
                    }, {
                        cancelled: () => uploadState.cancelRequested,
                        intervalMs: 900,
                    });
                    const response = await uploadState.activeSectionXhr;
                    const rows = Array.isArray(response?.data)
                        ? response.data
                        : (response?.data ? [response.data] : []);

                    if (!await completeBatchRows(batch, rows)) {
                        return;
                    }
                } catch (xhr) {
                    uploadState.activeSectionXhr = null;
                    stopSectionProgress();
                    if (uploadState.cancelRequested || xhr?.statusText === 'abort') {
                        resetUploadProgress();
                        return;
                    }

                    batch.forEach(({ index: sectionIndex }) => {
                        uploadState.preparedSections[sectionIndex].status = 'Failed';
                    });
                    $status.text('Failed');
                    renderQueue();
                    notifyError(buildUploadSessionErrorMessage(xhr));
                    uploadState.uploadInFlight = false;
                    syncUploadControls();
                    return;
                }
            }
        } else {
        for (let index = 0; index < uploadState.preparedSections.length; index += 1) {
            if (uploadState.cancelRequested || uploadState.pauseRequested) {
                break;
            }

            if (uploadState.preparedSections[index].status === 'Complete') {
                continue;
            }

            uploadState.preparedSections[index].status = 'Processing';
            $status.text(`Processing ${index + 1} of ${uploadState.preparedSections.length}`);
            const offline = getTranscriptionEngine() === 'offline';
            const progressId = offline ? createTranscriptionProgressId() : '';
            beginSectionProgress(index + 1, progressId, offline && !uploadState.preparedSections[index].preparedSkipped);
            renderQueue();

            const section = uploadState.preparedSections[index];
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
            formData.append('finalize_session', index === uploadState.preparedSections.length - 1 ? '1' : '0');

            try {
                uploadState.activeSectionXhr = backgroundAjax({
                    url: audioChunkUrl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                }, {
                    cancelled: () => uploadState.cancelRequested,
                    intervalMs: 900,
                });
                const response = await uploadState.activeSectionXhr;

                const chunk = response?.data || {};
                rememberStoredUploadItem(chunk);
                rememberUploadCategory(categoryName);
                await completeSectionProgress();

                if (uploadState.cancelRequested) {
                    uploadState.activeSectionXhr = null;
                    resetUploadProgress();
                    return;
                }

                uploadState.preparedSections[index] = {
                    ...section,
                    status: 'Complete',
                    text: chunk.translated_text || '',
                    timestamps: chunk.transcription_timestamps || [],
                    preparedMeta: chunk.prepared_file_size_bytes
                        ? `${formatBytes(Number(chunk.prepared_file_size_bytes))} sent`
                        : '',
                };
                if (getTranscriptionEngine() === 'offline') {
                    const completedSections = uploadState.preparedSections.filter((item) => item.status === 'Complete').length;
                    markUploadSherpaProgress(
                        uploadState.preparedSections.length > 0
                            ? Math.round((completedSections / uploadState.preparedSections.length) * 100)
                            : 100,
                        `Processing ${Math.min(index + 1, uploadState.preparedSections.length)} of ${uploadState.preparedSections.length}`,
                    );
                } else if (chunk.status === 'diarization_queued' && Number(chunk.id || 0) > 0) {
                    queuedDiarizationIds.push(Number(chunk.id));
                    restartQueuedDiarizationMonitor(queuedDiarizationIds);
                }
                uploadState.activeSectionXhr = null;
                renderQueue();
                renderTranscript();
            } catch (xhr) {
                uploadState.activeSectionXhr = null;
                stopSectionProgress();
                if (uploadState.cancelRequested || xhr?.statusText === 'abort') {
                    resetUploadProgress();
                    return;
                }

                uploadState.preparedSections[index].status = 'Failed';
                $status.text('Failed');
                renderQueue();
                notifyError(buildUploadSessionErrorMessage(xhr));
                uploadState.uploadInFlight = false;
                syncUploadControls();
                return;
            }
        }
        }

        const pausedWithWorkRemaining = uploadState.pauseRequested && hasUnfinishedSections();
        stopSectionProgress();

        if (uploadState.cancelRequested) {
            resetUploadProgress();
            return;
        } else if (pausedWithWorkRemaining) {
            $status.text('Paused');
        } else {
            $status.text('Complete');
        }
        uploadState.uploadInFlight = false;
        syncUploadControls();
        if (!uploadState.cancelRequested && !pausedWithWorkRemaining) {
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
        const formData = new FormData();

        if (uploadState.selectedFile?.localPath) {
            formData.append('local_path', uploadState.selectedFile.localPath);
            if (uploadState.selectedDurationMs > 0) {
                formData.append('duration_ms', String(Math.round(uploadState.selectedDurationMs)));
            }
        } else {
            formData.append('audio_file', uploadState.selectedFile);
        }

        formData.append('chunk_seconds', String(Math.floor(getChunkLengthMs() / 1000)));

        uploadState.sourceUploadXhr = $.ajax({
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

        return uploadState.sourceUploadXhr;
    };

    const selectUploadFile = (file) => {
        uploadState.selectedFile = file;
        uploadState.selectedDurationMs = Number(file?.durationMs || 0);
        uploadState.preparedSections = [];
        uploadState.cleanedSections = [];
        uploadState.cleanerStatus = 'Waiting';
        uploadState.cleanerCompletedBatches = 0;
        uploadState.currentSessionId = '';
        uploadState.currentCategoryName = '';
        uploadState.cancelRequested = false;
        uploadState.uploadCancelling = false;
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
        if (uploadState.metadataUrl) {
            URL.revokeObjectURL(uploadState.metadataUrl);
        }

        uploadState.metadataUrl = URL.createObjectURL(file);
        const audio = new Audio();
        audio.preload = 'metadata';
        audio.src = uploadState.metadataUrl;

        audio.addEventListener('loadedmetadata', () => {
            uploadState.selectedDurationMs = Number.isFinite(audio.duration) && audio.duration > 0
                ? audio.duration * 1000
                : 0;

            syncPlan();
        }, { once: true });

        audio.addEventListener('error', () => {
        uploadState.selectedDurationMs = Number(file?.durationMs || 0);
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
        const item = uploadState.uploadStoredItems.find((entry) => String(entry.id) === id);

        if (item) {
            playUploadStoredItem(item);
        }
    });

    $transcriptList.on('click', '[data-upload-stored-action="remove"]', function () {
        const id = String($(this).closest('[data-upload-stored-item]').attr('data-upload-stored-item') || '');
        const item = uploadState.uploadStoredItems.find((entry) => String(entry.id) === id);

        if (item) {
            deleteUploadStoredItem(item);
        }
    });


    $queueButton.on('click', async function () {
        if (!uploadState.selectedFile || uploadState.uploadInFlight || !uploadUrl || !audioChunkUrl) {
            notifyError('Upload endpoints are not ready. Refresh the page and try again.');
            return;
        }

        const categoryName = getUploadCategory();
        if (!categoryName) {
            notifyError('Choose a project name before processing the upload.');
            $categoryInput.trigger('focus');
            return;
        }

        uploadState.preparedSections = buildSections();
        uploadState.preparedSections = uploadState.preparedSections.map((section) => ({
            ...section,
            status: 'Waiting',
            preparedMeta: uploadState.selectedFile.localPath ? 'Waiting for source preparation' : 'Waiting for source upload',
        }));
        uploadState.cleanedSections = [];
        uploadState.cleanerStatus = 'Waiting';
        uploadState.cleanerCompletedBatches = 0;
        uploadState.uploadCancelling = false;
        setProcessingState(true);
        $status.text(uploadState.selectedFile.localPath ? 'Preparing source' : 'Uploading source 0%');
        renderQueue();

        try {
            const response = uploadState.currentSessionId
                ? { data: { session_id: uploadState.currentSessionId, sections: uploadState.preparedSections.map((section) => ({
                    index: section.index,
                    start_ms: section.startMs,
                    end_ms: section.endMs,
                    duration_ms: section.durationMs,
                    range_label: section.rangeLabel,
                })) } }
                : await prepareUploadSession();
            uploadState.sourceUploadXhr = null;
            const sessionId = String(response?.data?.session_id || '');
            const sections = Array.isArray(response?.data?.sections) ? response.data.sections : [];

            if (!sessionId || !sections.length) {
                uploadState.preparedSections = uploadState.preparedSections.map((section) => ({
                    ...section,
                    status: 'Failed',
                }));
                $status.text('Failed');
                renderQueue();
                notifyError('Audio upload could not be prepared.');
                uploadState.uploadInFlight = false;
                syncUploadControls();
                return;
            }

            uploadState.currentSessionId = sessionId;
            uploadState.currentCategoryName = categoryName;
            uploadState.selectedDurationMs = Number(response?.data?.duration_ms || uploadState.selectedDurationMs || 0);
            syncPlan();
            uploadState.preparedSections = sections.map((section, index) => ({
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

            if (prepared && !uploadState.pauseRequested) {
                try {
                    await startPreparedUploadDiarization(sessionId);
                } catch (xhr) {
                    if (uploadState.cancelRequested || xhr?.statusText === 'abort') {
                        resetUploadProgress();
                        return;
                    }
                }
                await processUploadSections(sessionId, categoryName);
            } else if (uploadState.pauseRequested) {
                $status.text('Paused');
                uploadState.uploadInFlight = false;
                syncUploadControls();
            } else {
                uploadState.uploadInFlight = false;
                syncUploadControls();
            }
        } catch (xhr) {
            uploadState.sourceUploadXhr = null;
            if (uploadState.cancelRequested || xhr?.statusText === 'abort') {
                resetUploadProgress();
                return;
            }

            uploadState.preparedSections = uploadState.preparedSections.map((section) => ({
                ...section,
                status: 'Failed',
            }));
            $status.text('Failed');
            renderQueue();
            notifyError(buildUploadSessionErrorMessage(xhr));
            uploadState.uploadInFlight = false;
            syncUploadControls();
        }
    });

    $continueButton.on('click', function () {
        if (!uploadState.currentSessionId || uploadState.uploadInFlight || !hasUnfinishedSections()) {
            return;
        }

        uploadState.cancelRequested = false;
        uploadState.uploadCancelling = false;
        uploadState.pauseRequested = false;
        setProcessingState(true);
        prepareUploadSections(uploadState.currentSessionId, uploadState.currentCategoryName || getUploadCategory())
            .then((prepared) => {
                if (prepared && !uploadState.pauseRequested) {
                    return processUploadSections(uploadState.currentSessionId, uploadState.currentCategoryName || getUploadCategory());
                }
                if (uploadState.pauseRequested) {
                    $status.text('Paused');
                }
                uploadState.uploadInFlight = false;
                syncUploadControls();
                return null;
            });
    });

    $retryButton.on('click', function () {
        if (!uploadState.currentSessionId || uploadState.uploadInFlight || !hasRetryableSections()) {
            return;
        }

        uploadState.preparedSections = uploadState.preparedSections.map((section) => ['Failed', 'Cancelled'].includes(section.status)
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
        uploadState.cancelRequested = false;
        uploadState.uploadCancelling = false;
        uploadState.pauseRequested = false;
        setProcessingState(true);
        renderQueue();
        prepareUploadSections(uploadState.currentSessionId, uploadState.currentCategoryName || getUploadCategory())
            .then((prepared) => {
                if (prepared && !uploadState.pauseRequested) {
                    return processUploadSections(uploadState.currentSessionId, uploadState.currentCategoryName || getUploadCategory());
                }
                if (uploadState.pauseRequested) {
                    $status.text('Paused');
                }
                uploadState.uploadInFlight = false;
                syncUploadControls();
                return null;
            });
    });

    $pauseButton.on('click', function () {
        if (!uploadState.uploadInFlight || !uploadState.currentSessionId || uploadState.pauseRequested) {
            return;
        }

        uploadState.pauseRequested = true;
        $status.text('Pausing');
        syncUploadControls();
    });

    $cancelButton.on('click', function () {
        if (!uploadState.uploadInFlight && !hasUploadProgress()) {
            return;
        }

        uploadState.cancelRequested = true;
        uploadState.pauseRequested = false;
        const speakerSessionId = uploadState.currentSessionId;
        const hadActiveRequest = Boolean(uploadState.sourceUploadXhr || uploadState.activeSectionXhr || uploadState.activePrepareXhrs.length);

        cancelWhisperProgress(uploadState.activeSectionProgressId);
        cancelActiveBackgroundJobs();
        showUploadCancellingState();

        if (uploadState.sourceUploadXhr) {
            uploadState.sourceUploadXhr.abort();
        }

        uploadState.activePrepareXhrs.forEach((xhr) => xhr?.abort?.());
        uploadState.activePrepareXhrs = [];

        if (uploadState.activeSectionXhr) {
            uploadState.activeSectionXhr.abort();
        }

        window.setTimeout(() => {
            if (uploadState.cancelRequested || uploadState.uploadCancelling) {
                resetUploadProgress('Cancelled');
            }
        }, hadActiveRequest ? 350 : 0);
        releaseSpeakerSession(speakerSessionId);
    });

    window.addEventListener('pagehide', () => {
        releaseSpeakerSession(uploadState.currentSessionId, true);
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

                    uploadState.currentSessionId = String(stored.sessionId);
                    uploadState.currentCategoryName = String(stored.categoryName || '');
                    uploadState.selectedDurationMs = Number(stored.durationMs || 0);
                    uploadState.preparedSections = stored.sections.map((section) => ({
                        ...section,
                        status: section.status === 'Processing' ? 'Cancelled' : section.status,
                        preparedMeta: section.status === 'Processing' ? 'Ready to continue' : section.preparedMeta,
                    }));

                    if (!hasUnfinishedSections()) {
                        resetUploadProgress();
                        return;
                    }

                    if (uploadState.currentCategoryName && !$categoryInput.val()) {
                        $categoryInput.val(uploadState.currentCategoryName);
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

};
