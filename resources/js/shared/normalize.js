// Normalizes stored transcript / audio-chunk items returned by the backend
// into a consistent camelCase shape for the UI. Shared by the upload page
// (normalizeUploadStoredItem) and the live page (normalizeStoredTranscriptItem),
// which previously duplicated the same snake_case -> camelCase fallbacks.

const missing = (emptyAsNull) => (emptyAsNull ? null : '');

export const normalizeStoredItem = (item = {}, options = {}) => {
    const {
        playUrlBase = '',
        deleteUrlBase = '',
        defaultSourceType = 'upload',
        emptyAsNull = false,
    } = options;

    const fallback = missing(emptyAsNull);
    const id = item.id;

    return {
        ...item,
        id,
        rangeLabel: item.rangeLabel || item.range_label || fallback,
        categoryName: item.categoryName || item.category_name || fallback,
        playUrl: item.play_url || item.playUrl || (id ? `${playUrlBase}/${id}/audio` : fallback),
        deleteUrl: item.delete_url || item.deleteUrl || (id ? `${deleteUrlBase}/${id}` : fallback),
        translatedText: item.translatedText || item.translated_text || fallback,
        clipStartMs: Number(item.clipStartMs || item.clip_start_ms || 0),
        clipEndMs: Number(item.clipEndMs || item.clip_end_ms || 0),
        sourceType: item.sourceType || item.source_type || defaultSourceType,
    };
};
