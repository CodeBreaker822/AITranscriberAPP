(() => {
    'use strict';

    const activeCancelUrls = new Set();
    const pause = (milliseconds) => new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });

    const request = async (options, pollOptions = {}) => {
        const $ = window.jQuery;

        if (!$?.ajax) {
            throw new Error('Background requests require jQuery.');
        }

        const response = await $.ajax({
            ...options,
            headers: {
                ...(options.headers || {}),
                'X-AITranscriber-Background': '1',
            },
        });

        if (response?.background !== true || !response?.status_url) {
            return response;
        }

        const cancelUrl = String(response.cancel_url || '');
        if (cancelUrl) {
            activeCancelUrls.add(cancelUrl);
        }

        try {
            const deadline = Date.now() + Number(pollOptions.timeoutMs || 600000);
            const failureMessage = String(pollOptions.failureMessage || 'Background processing failed.');
            const cancelledMessage = String(pollOptions.cancelledMessage || 'Background processing was cancelled.');
            const timeoutMessage = String(pollOptions.timeoutMessage || 'Background processing did not finish in time. Please try again.');

            while (true) {
                if (pollOptions.cancelled?.()) {
                    if (cancelUrl) {
                        $.ajax({ url: cancelUrl, method: 'POST' }).catch(() => {});
                    }

                    throw { statusText: 'abort' };
                }

                if (Date.now() > deadline) {
                    throw {
                        status: 504,
                        statusText: 'timeout',
                        responseJSON: { message: timeoutMessage },
                    };
                }

                await pause(Number(pollOptions.intervalMs || 900));
                const status = await $.getJSON(response.status_url);
                const state = String(status?.status || '');

                if (state === 'completed') {
                    const httpStatus = Number(status?.http_status || 200);
                    if (httpStatus >= 400) {
                        throw {
                            status: httpStatus,
                            statusText: 'error',
                            responseJSON: status.response || { message: failureMessage },
                        };
                    }

                    return status.response || {};
                }

                if (state === 'failed' || state === 'cancelled') {
                    throw {
                        status: Number(status?.http_status || (state === 'cancelled' ? 499 : 500)),
                        statusText: state === 'cancelled' ? 'abort' : 'error',
                        responseJSON: {
                            message: String(status?.message || (state === 'cancelled' ? cancelledMessage : failureMessage)),
                        },
                    };
                }
            }
        } finally {
            if (cancelUrl) {
                activeCancelUrls.delete(cancelUrl);
            }
        }
    };

    const cancelAll = () => {
        const $ = window.jQuery;

        if (!$?.ajax) {
            return;
        }

        activeCancelUrls.forEach((url) => {
            $.ajax({ url, method: 'POST' }).catch(() => {});
        });
    };

    window.AITranscriberBackgroundJobs = {
        request,
        ajax: request,
        cancelAll,
    };
})();
