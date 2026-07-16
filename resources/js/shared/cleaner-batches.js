// Row-based batching for transcript polishing/cleaning. Audio processing
// progresses by concrete stored chunk rows, so polishing follows those same
// rows instead of inferring work from possibly duplicated clip timestamps.

const chunkIdOf = (item) => Number(item?.id || item?.audioChunkId || item?.audio_chunk_id || 0);
const timestampCountOf = (item) => {
    const timestamps = item?.timestamps || item?.transcription_timestamps || item?.cleanTimestamps || item?.clean_timestamps || [];

    return Array.isArray(timestamps) ? timestamps.length : 0;
};

export const buildCleanerBatches = (items = []) => (Array.isArray(items) ? items : [])
    .map((item, index) => ({
        item,
        index,
        audioChunkId: chunkIdOf(item),
        timestampCount: timestampCountOf(item),
    }))
    .filter((batch) => batch.audioChunkId > 0)
    .map((batch, position) => ({
        audioChunkIds: [batch.audioChunkId],
        position: position + 1,
        timestampCount: batch.timestampCount,
        item: batch.item,
    }));

export const countCleanerBatches = (items = []) => buildCleanerBatches(items).length;
