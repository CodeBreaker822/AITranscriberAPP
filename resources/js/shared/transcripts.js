import { escapeHtml } from './dom.js';

export const isUsefulTranscriptText = (value) => {
    const normalized = String(value || '').trim().toLowerCase();

    return normalized !== '' && normalized !== 'no speech detected' && normalized !== 'no speech detected.';
};

export const hasUsefulTranscript = (item) => isUsefulTranscriptText(
    item?.translatedText || item?.translated_text || item?.text || '',
);

export const speakerLabel = (speakerId) => {
    const match = String(speakerId || '').match(/(\d+)$/);

    return match ? `Speaker ${Math.max(1, Number(match[1]))}` : 'Speaker';
};

export const appendTranscriptPart = (current, part) => {
    const text = String(part || '').trim();

    if (!current || !text) {
        return current || text;
    }

    return /^[.,!?;:%)\]}]/u.test(text) || /[(\[{]$/u.test(current)
        ? `${current}${text}`
        : `${current} ${text}`;
};

export const speakerTurnsFromTimestamps = (timestamps = []) => {
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

    return turns;
};

export const transcriptTextWithSpeakerTurns = (text, timestamps = []) => {
    const turns = speakerTurnsFromTimestamps(timestamps);

    if (!turns.length) {
        return String(text || '').trim();
    }

    return turns
        .map((turn) => `${speakerLabel(turn.speakerId)}: ${turn.text}`)
        .join('\n');
};

export const buildExportRows = (items, useCleaned) => items
    .map((item) => {
        const transcript = useCleaned
            ? (item.cleanText || item.clean_text || '')
            : (item.translatedText || item.translated_text || item.text || '');
        const timestamps = useCleaned
            ? (item.cleanTimestamps || item.clean_timestamps || [])
            : (item.timestamps || item.transcription_timestamps || []);
        const turns = speakerTurnsFromTimestamps(timestamps);
        const speakerLabels = [...new Set(turns.map((turn) => speakerLabel(turn.speakerId)))];

        return {
            rangeLabel: item.rangeLabel || item.range_label || '',
            transcriptText: transcriptTextWithSpeakerTurns(transcript, timestamps),
            turns,
            speakerLabels,
        };
    })
    .filter((row) => isUsefulTranscriptText(row.transcriptText));

export const renderTranscriptText = (text, timestamps = []) => {
    const turns = speakerTurnsFromTimestamps(timestamps);

    if (!turns.length) {
        return `<p class="whitespace-pre-line break-words text-xs leading-5 text-slate-100">${escapeHtml(text)}</p>`;
    }

    return `
        <div class="space-y-1.5" data-speaker-turns>
            ${turns.map((turn) => `
                <div class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-2" data-speaker-id="${escapeHtml(turn.speakerId)}">
                    <span class="whitespace-nowrap text-xs font-semibold leading-5 text-cyan-300">${escapeHtml(speakerLabel(turn.speakerId))}:</span>
                    <span class="break-words text-xs leading-5 text-slate-100">${escapeHtml(turn.text)}</span>
                </div>
            `).join('')}
        </div>
    `;
};
