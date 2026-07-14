import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
    const [key, ...value] = arg.replace(/^--/, '').split('=');
    return [key, value.join('=') || 'true'];
}));
const baseUrl = String(args.base || process.env.AI_TRANSCRIBER_DIAGNOSTIC_URL || 'http://127.0.0.1:8010').replace(/\/+$/, '');
const audioRoot = path.join(root, 'storage/app/private/diagnostics/real-workflow');
const reportRoot = path.join(root, 'storage/app/private/diagnostics/reports');
const audioFiles = [1, 2, 3, 4].map((index) => path.join(audioRoot, `real-workflow-0${index}-60s.wav`));
const runId = timestamp();
const uploadCategory = `RealWorkflow-Upload-${runId}`;
const liveCategory = `RealWorkflow-Live-${runId}`;
const cookieJar = new Map();

class HttpError extends Error {
    constructor(message, response) {
        super(message);
        this.response = response;
    }
}

const cookieHeader = () => [...cookieJar.entries()].map(([key, value]) => `${key}=${value}`).join('; ');

const storeCookies = (headers) => {
    const combined = headers.get('set-cookie') || '';
    combined.split(/,(?=[^;,]+=)/).forEach((cookie) => {
        const [pair] = cookie.trim().split(';');
        const index = pair.indexOf('=');

        if (index > 0) {
            cookieJar.set(pair.slice(0, index), pair.slice(index + 1));
        }
    });
};

const request = async (url, options = {}) => {
    const headers = new Headers(options.headers || {});

    if (cookieJar.size > 0) {
        headers.set('Cookie', cookieHeader());
    }

    let response;

    try {
        response = await fetch(`${baseUrl}${url}`, {
            ...options,
            headers,
        });
    } catch (error) {
        throw new HttpError(`Request failed for ${options.method || 'GET'} ${url}: ${error.cause?.message || error.message}`, {
            cause: error.cause?.message || error.message,
        });
    }

    storeCookies(response.headers);

    return response;
};

const page = async (url) => {
    const response = await request(url, { headers: { Accept: 'text/html' } });
    const text = await response.text();

    if (!response.ok) {
        throw new HttpError(`${url} returned HTTP ${response.status}`, { status: response.status, text });
    }

    return text;
};

const csrfFrom = (html) => {
    const match = html.match(/name="csrf-token"\s+content="([^"]+)"/) || html.match(/name="_token"\s+value="([^"]+)"/);

    if (!match) {
        throw new Error('CSRF token was not found.');
    }

    return match[1];
};

const json = async (url, token, body) => {
    const response = await request(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const text = await response.text();
    const payload = parseJson(text);

    if (!response.ok) {
        throw new HttpError(messageFrom(payload, text, response.status), { status: response.status, payload, text });
    }

    return { status: response.status, payload };
};

const form = async (url, token, fields, files) => {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, String(value)));
    files.forEach(({ key, file, name, type }) => {
        body.append(key, new File([readFileSync(file)], name || path.basename(file), { type }));
    });

    const response = await request(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body,
    });
    const text = await response.text();
    const payload = parseJson(text);

    if (!response.ok) {
        throw new HttpError(messageFrom(payload, text, response.status), { status: response.status, payload, text });
    }

    return { status: response.status, payload };
};

const parseJson = (text) => {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
};

const messageFrom = (payload, text, status) => payload?.message || text.slice(0, 300) || `HTTP ${status}`;

const transcriptPreview = (rows) => String(rows?.[0]?.translated_text || rows?.[0]?.translatedText || '').slice(0, 240);

const sectionPayload = (section) => ({
    clip_index: section.index,
    clip_start_ms: section.start_ms,
    clip_end_ms: section.end_ms,
    duration_ms: section.duration_ms,
    range_label: section.range_label,
});

const batchSectionPayload = (section) => ({
    clip_index: section.clip_index,
    clip_start_ms: section.clip_start_ms,
    clip_end_ms: section.clip_end_ms,
    duration_ms: section.duration_ms,
    range_label: section.range_label,
    audio_chunk_id: section.audio_chunk_id || '',
    prepared_name: section.prepared_name || '',
    source_name: section.source_name || '',
    prepared_skipped: Boolean(section.prepared_skipped),
});

async function runLive(token, liveHtml) {
    console.log('diagnostic step: live endpoint');
    const disabledInHtml = /<button[\s\S]*?data-record-toggle[\s\S]*?\sdisabled(?:\s|>|=)/i.test(liveHtml);
    const speakerSessionId = `diagnostic-live-${runId}`;
    const response = await form('/audio-chunks', token, {
        user_id: 1,
        category_name: liveCategory,
        clip_index: 1,
        clip_start_ms: 0,
        clip_end_ms: 60000,
        range_label: '00:00-01:00',
        duration_ms: 60000,
        language_code: '',
        transcription_engine: 'online',
        speaker_session_id: speakerSessionId,
        finalize_session: 1,
    }, [{
        key: 'audio',
        file: audioFiles[0],
        name: 'live-diagnostic-01.wav',
        type: 'audio/wav',
    }]);

    return {
        category: liveCategory,
        disabledInHtml,
        status: response.status,
        message: response.payload?.message || '',
        rows: Array.isArray(response.payload?.data) ? response.payload.data : [response.payload?.data].filter(Boolean),
    };
}

async function runUpload(token) {
    const results = [];

    for (const [index, file] of audioFiles.entries()) {
        console.log(`diagnostic step: upload audio ${index + 1}`);
        const upload = await form('/audio-uploads', token, {
            chunk_seconds: 60,
        }, [{
            key: 'audio_file',
            file,
            name: path.basename(file),
            type: 'audio/wav',
        }]);
        const sections = upload.payload?.data?.sections || [];
        const prepare = await json('/audio-uploads/sections/prepare-batch', token, {
            upload_session_id: upload.payload.data.session_id,
            category_name: uploadCategory,
            concurrency: 2,
            sections: sections.map(sectionPayload),
        });
        const transcribe = await json('/audio-chunks/batch', token, {
            upload_session_id: upload.payload.data.session_id,
            category_name: uploadCategory,
            language_code: '',
            transcription_engine: 'online',
            finalize_session: true,
            sections: (prepare.payload?.data || []).map(batchSectionPayload),
        });

        results.push({
            index: index + 1,
            file,
            uploadStatus: upload.status,
            sectionCount: sections.length,
            prepareStatus: prepare.status,
            prepareMessage: prepare.payload?.message || '',
            transcribeStatus: transcribe.status,
            transcribeMessage: transcribe.payload?.message || '',
            rows: Array.isArray(transcribe.payload?.data) ? transcribe.payload.data : [],
        });
    }

    return {
        category: uploadCategory,
        results,
    };
}

async function polishAndSummarize(token, category) {
    console.log(`diagnostic step: polish ${category}`);
    const polish = await json('/transcripts/furnish', token, {
        category_name: category,
        instructions: 'Clean transcription errors while preserving speaker meaning.',
    });
    console.log(`diagnostic step: summarize ${category}`);
    const summary = await json('/transcripts/summary', token, {
        category_name: category,
        source_type: 'cleaned',
    });

    return {
        polishStatus: polish.status,
        polishMessage: polish.payload?.message || '',
        polishCount: polish.payload?.count ?? 0,
        summaryStatus: summary.status,
        summaryMessage: summary.payload?.message || '',
        provider: summary.payload?.data?.provider || '',
        model: summary.payload?.data?.model || '',
        summaryText: summary.payload?.data?.summary_text || '',
    };
}

function report({ live, liveText, upload, uploadText }) {
    const lines = [
        '# Real Workflow Diagnostic Report',
        '',
        `- Generated: ${new Date().toISOString()}`,
        `- Base URL: ${baseUrl}`,
        `- Mode: online`,
        `- Live category: ${live.category}`,
        `- Upload category: ${upload.category}`,
        '',
        '## Live Path',
        '',
        'Uses the same endpoint as the Live start workflow after the browser records a clip: `POST /audio-chunks` with the `audio` file field.',
        '',
        `- Record button disabled in initial HTML: ${live.disabledInHtml ? 'yes' : 'no'}`,
        `- Status: ${live.status}`,
        `- Message: ${live.message}`,
        `- Saved rows: ${live.rows.length}`,
        `- Transcript preview: ${transcriptPreview(live.rows)}`,
        '',
        '### Live Polish And Summary',
        '',
        `- Polish: ${liveText.polishStatus} ${liveText.polishMessage}`,
        `- Polished rows: ${liveText.polishCount}`,
        `- Summary: ${liveText.summaryStatus} ${liveText.summaryMessage}`,
        `- Provider: ${liveText.provider}`,
        `- Model: ${liveText.model}`,
        '',
        '```text',
        String(liveText.summaryText).slice(0, 1600),
        '```',
        '',
        '## Upload Path',
        '',
        'Uses the same uploaded-audio endpoint chain as the Upload page: `/audio-uploads`, `/audio-uploads/sections/prepare-batch`, `/audio-chunks/batch`.',
        '',
    ];

    upload.results.forEach((item) => {
        lines.push(
            `### Upload Audio ${item.index}`,
            '',
            `- File: \`${item.file}\``,
            `- Upload: ${item.uploadStatus}`,
            `- Sections: ${item.sectionCount}`,
            `- Prepare: ${item.prepareStatus} ${item.prepareMessage}`,
            `- Transcribe: ${item.transcribeStatus} ${item.transcribeMessage}`,
            `- Saved rows: ${item.rows.length}`,
            `- Transcript preview: ${transcriptPreview(item.rows)}`,
            '',
        );
    });

    lines.push(
        '### Upload Polish And Summary',
        '',
        `- Polish: ${uploadText.polishStatus} ${uploadText.polishMessage}`,
        `- Polished rows: ${uploadText.polishCount}`,
        `- Summary: ${uploadText.summaryStatus} ${uploadText.summaryMessage}`,
        `- Provider: ${uploadText.provider}`,
        `- Model: ${uploadText.model}`,
        '',
        '```text',
        String(uploadText.summaryText).slice(0, 1600),
        '```',
        '',
    );

    return lines.join('\n');
}

function timestamp() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
}

async function main() {
    audioFiles.forEach((file) => {
        if (!existsSync(file)) {
            throw new Error(`Missing diagnostic audio: ${file}`);
        }
    });
    mkdirSync(reportRoot, { recursive: true });

    console.log('diagnostic step: live page');
    const liveHtml = await page('/');
    const token = csrfFrom(liveHtml);
    console.log('diagnostic step: upload page');
    await page('/upload');

    const live = await runLive(token, liveHtml);
    const upload = await runUpload(token);
    const liveText = await polishAndSummarize(token, live.category);
    const uploadText = await polishAndSummarize(token, upload.category);
    const output = report({ live, liveText, upload, uploadText });
    const reportPath = path.join(reportRoot, `${runId}-real-workflow.md`);

    writeFileSync(reportPath, output, 'utf8');
    console.log(reportPath);
}

main().catch((error) => {
    if (error instanceof HttpError) {
        console.error(error.message);
        console.error(JSON.stringify(error.response, null, 2));
    } else {
        console.error(error.stack || error.message);
    }
    process.exitCode = 1;
});
