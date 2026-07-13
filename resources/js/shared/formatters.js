export const formatClock = (milliseconds) => {
    const totalSeconds = Math.max(0, Math.floor(Number(milliseconds || 0) / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
};

export const formatClipRange = (startMs, endMs) => `${formatClock(startMs)}-${formatClock(endMs)}`;

export const formatRelativeClock = (milliseconds) => `+${formatClock(milliseconds)}`;

export const formatBytes = (bytes) => {
    const size = Number(bytes || 0);

    if (!Number.isFinite(size) || size <= 0) {
        return '0 MB';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
    const value = size / (1024 ** index);

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[index]}`;
};

export const slugify = (value, fallback = 'transcription') => String(value || fallback)
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '') || fallback;

export const sortByTimeAscending = (first, second) => {
    const firstStart = Number(first?.clipStartMs || first?.clip_start_ms || 0);
    const secondStart = Number(second?.clipStartMs || second?.clip_start_ms || 0);

    return firstStart === secondStart
        ? Number(first?.id || first?.audioChunkId || first?.audio_chunk_id || 0) - Number(second?.id || second?.audioChunkId || second?.audio_chunk_id || 0)
        : firstStart - secondStart;
};
