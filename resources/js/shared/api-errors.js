export const buildUploadErrorMessage = (xhr, item) => {
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

export const buildUploadSessionErrorMessage = (xhr) => {
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

export const buildStorageLoadErrorMessage = (xhr, selectedCategory) => {
    const serverMessage = String(xhr?.responseJSON?.message || '').trim();

    if (serverMessage) {
        return serverMessage;
    }

    const target = selectedCategory ? `for "${selectedCategory}"` : '';
    return `Could not load saved recordings ${target}.`;
};

export const buildDeleteErrorMessage = () => 'Could not remove this clip right now.';
