import { initLivePage } from './live/live-controller.js';
import { initSettingsPage } from './settings/settings-controller.js';
import { initUploadPage } from './upload/upload-controller.js';
import { clampProgressPercent } from './shared/progress.js';

$(function () {
    const $body = $('body');
    const completeDesktopStartup = () => {
        $('[data-desktop-startup-overlay]').fadeOut(160, function () {
            $(this).remove();
        });
    };
    const failDesktopStartup = (error) => {
        window.console?.error?.('AITranscriber frontend could not initialize.', error);
        $('[data-desktop-startup-status]').text('Startup error');
        $('[data-desktop-startup-overlay] p').text('The interface could not finish loading. Check the developer console for details.');
    };
    const audioChunkSeconds = Math.max(1, Number($body.data('audio-chunk-seconds') || 60) || 60);
    const audioChunkLengthMs = audioChunkSeconds * 1000;
    const audioChunkDurationLabel = (() => {
        if (audioChunkSeconds % 60 === 0) {
            const minutes = audioChunkSeconds / 60;

            return minutes === 1 ? 'one-minute' : `${minutes}-minute`;
        }

        return audioChunkSeconds === 1 ? 'one-second' : `${audioChunkSeconds}-second`;
    })();
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
            const percent = clampProgressPercent(event?.payload?.percent);
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


    const context = {
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
    };

    try {
        if ($body.data('page') === 'upload') {
            initUploadPage(context);
            completeDesktopStartup();
            return;
        }

        if ($body.data('page') === 'settings') {
            initSettingsPage();
            completeDesktopStartup();
            return;
        }

        if ($body.data('page') === 'live') {
            initLivePage(context);
        }

        completeDesktopStartup();
    } catch (error) {
        failDesktopStartup(error);
    }
});
