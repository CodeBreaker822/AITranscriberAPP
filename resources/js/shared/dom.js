export const escapeHtml = (value) => {
    const element = document.createElement('div');
    element.textContent = String(value || '');

    return element.innerHTML;
};

export const notify = (message, type = 'success') => {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);
    }
};

export const notifyError = (message) => {
    notify(message, 'error');
};

export const withButtonLoading = async ($button, callback) => {
    if (typeof window.toggleLoading === 'function' && $button?.length) {
        window.toggleLoading($button, true);
    }

    try {
        return await callback();
    } finally {
        if (typeof window.toggleLoading === 'function' && $button?.length) {
            window.toggleLoading($button, false);
        }
    }
};

export const readNearbyControlValue = (event, selector, fallback = '') => {
    const $trigger = window.$(event?.currentTarget || []);
    const nearby = $trigger.siblings(selector).first().val();

    return nearby !== undefined ? nearby : (window.$(selector).first().val() ?? fallback);
};
