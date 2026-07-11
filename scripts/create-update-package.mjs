import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    cpSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    renameSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import readline from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseRoot = path.join(projectRoot, 'src-tauri', 'target', 'release');
const buildType = process.argv[2] === 'empty' ? 'empty' : 'standard';

if (process.platform !== 'win32') {
    throw new Error('AITranscriber update packages must be built on Windows.');
}

const tauriConfig = JSON.parse(
    readFileSync(path.join(projectRoot, 'src-tauri', 'tauri.conf.json'), 'utf8'),
);
const version = tauriConfig.version;
const platformName = `windows-${process.arch}`;
const defaultOutputDirectory = path.join(releaseRoot, 'bundle', 'updates');
const envPath = path.join(projectRoot, '.env');
const envFile = existsSync(envPath) ? readFileSync(envPath, 'utf8') : '';
const envValue = (key) => {
    if (Object.prototype.hasOwnProperty.call(process.env, key)) {
        return process.env[key];
    }

    const match = envFile.match(new RegExp(`^\\s*${key}\\s*=\\s*(.*)$`, 'm'));

    return match ? match[1].trim().replace(/^['"]|['"]$/g, '') : '';
};
const envFlag = (key) => ['1', 'true', 'yes', 'on'].includes(String(envValue(key)).toLowerCase());
const appLogoOnly = envFlag('APP_LOGO_ONLY');
const privateBrandingPayloadDirectory = path.normalize('public/branding').toLowerCase();
const bundledSherpaModels = [
    {
        file: 'pyannote-segmentation-3.0-int8.onnx',
        bytes: 1_540_506,
        sha256: 'd582f4b4c6b48205de7e0643c57df0df5615a3c176189be3fc461e9d18827b5d',
    },
    {
        file: 'nemo-en-titanet-small.onnx',
        bytes: 40_257_283,
        sha256: 'ad4a1802485d8b34c722d2a9d04249662f2ece5d28a7a039063ca22f515a789e',
    },
];

const commonPayload = [
    'artisan',
    'composer.json',
    'version.json',
    'app',
    'bootstrap',
    'config',
    'database/factories',
    'database/migrations',
    'database/seeders',
    'public',
    'resources',
    'routes',
    'vendor',
    'vad',
    'sherpa',
];

const payload = [
    'aitranscriber.exe',
    'sherpa-onnx-c-api.dll',
    'onnxruntime.dll',
    'onnxruntime_providers_shared.dll',
    ...commonPayload,
    'php',
    'ffmpeg',
];
for (const relativePath of payload) {
    const normalized = relativePath.replaceAll('\\', '/').toLowerCase();
    const protectedPath = normalized === '.git'
        || normalized.startsWith('.git/')
        || normalized === '.git-broken'
        || normalized.startsWith('.git-broken/')
        || normalized === '.env'
        || normalized === 'database/database.sqlite'
        || normalized === 'storage'
        || normalized.startsWith('storage/')
        || normalized === 'whisper'
        || normalized.startsWith('whisper/');

    if (protectedPath) {
        throw new Error(`Update payload includes protected path: ${relativePath}`);
    }
}

function isGitMetadataPath(value) {
    return path.normalize(value)
        .split(path.sep)
        .some((part) => ['.git', '.git-broken'].includes(part.toLowerCase()));
}

function isLogoOnlyExcludedPath(value) {
    if (!appLogoOnly) {
        return false;
    }

    const relative = path.relative(releaseRoot, value);

    if (relative.startsWith('..') || path.isAbsolute(relative)) {
        return false;
    }

    const normalizedRelativePath = path.normalize(relative).toLowerCase();

    return normalizedRelativePath === privateBrandingPayloadDirectory
        || normalizedRelativePath.startsWith(`${privateBrandingPayloadDirectory}${path.sep}`);
}

function run(command, args, options = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            stdio: 'inherit',
            windowsHide: true,
            ...options,
        });

        child.on('error', reject);
        child.on('exit', (code, signal) => {
            if (code === 0 && !signal) {
                resolve();
                return;
            }

            reject(new Error(`${path.basename(command)} exited with code ${code ?? signal}.`));
        });
    });
}

function expandHome(value) {
    const trimmed = value.trim().replace(/^['"]|['"]$/g, '');

    if (trimmed === '~') {
        return os.homedir();
    }

    if (trimmed.startsWith(`~${path.sep}`) || trimmed.startsWith('~/')) {
        return path.join(os.homedir(), trimmed.slice(2));
    }

    return trimmed;
}

async function chooseOutputDirectory() {
    const configured = process.env.AITRANSCRIBER_UPDATE_OUTPUT_DIR?.trim();

    if (configured) {
        return path.resolve(expandHome(configured));
    }

    if (!process.stdin.isTTY || !process.stdout.isTTY) {
        console.log(`No interactive terminal detected; using ${defaultOutputDirectory}`);
        return defaultOutputDirectory;
    }

    const prompt = readline.createInterface({ input: process.stdin, output: process.stdout });

    try {
        const answer = await prompt.question(
            `Where should the update ZIP be saved? [${defaultOutputDirectory}] `,
        );

        return answer.trim()
            ? path.resolve(expandHome(answer))
            : defaultOutputDirectory;
    } finally {
        prompt.close();
    }
}

async function chooseReleaseNotes() {
    const configured = process.env.AITRANSCRIBER_UPDATE_NOTES?.trim();

    if (configured) {
        return configured;
    }

    const defaultNotes = `AITranscriber ${version} update.`;

    if (!process.stdin.isTTY || !process.stdout.isTTY) {
        return defaultNotes;
    }

    const prompt = readline.createInterface({ input: process.stdin, output: process.stdout });

    try {
        const answer = await prompt.question(`Release notes? [${defaultNotes}] `);

        return answer.trim() || defaultNotes;
    } finally {
        prompt.close();
    }
}

function copyPayload(stagingDirectory) {
    for (const relativePath of payload) {
        const source = path.join(releaseRoot, relativePath);

        if (!existsSync(source)) {
            throw new Error(`Update payload is missing required build output: ${source}`);
        }

        const destination = path.join(stagingDirectory, relativePath);
        mkdirSync(path.dirname(destination), { recursive: true });
        cpSync(source, destination, {
            recursive: true,
            force: true,
            filter: (sourcePath) => !isGitMetadataPath(sourcePath) && !isLogoOnlyExcludedPath(sourcePath),
        });
    }
}

function verifyReleaseSherpaModels() {
    for (const model of bundledSherpaModels) {
        const modelPath = path.join(releaseRoot, 'sherpa', 'models', model.file);

        if (!existsSync(modelPath) || statSync(modelPath).size !== model.bytes) {
            throw new Error(`Release Sherpa model is missing or incomplete: ${modelPath}`);
        }

        const digest = createHash('sha256').update(readFileSync(modelPath)).digest('hex');
        if (digest !== model.sha256) {
            throw new Error(`Release Sherpa model checksum failed: ${modelPath}`);
        }
    }
}

function assertProtectedFilesAreAbsent(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const entryPath = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            if (['.git', '.git-broken'].includes(entry.name.toLowerCase())) {
                throw new Error(`Refusing to package Git metadata: ${entryPath}`);
            }

            assertProtectedFilesAreAbsent(entryPath);
            continue;
        }

        if (
            entry.name === '.env'
            || entry.name.toLowerCase() === 'database.sqlite'
            || entry.name.toLowerCase() === 'ggml-large-v3-turbo-q8_0.bin'
        ) {
            throw new Error(`Refusing to package protected user file: ${entryPath}`);
        }
    }
}

async function zipDirectory(stagingDirectory, destination) {
    const escapePowerShell = (value) => value.replaceAll("'", "''");
    const command = [
        `$source = '${escapePowerShell(stagingDirectory)}'`,
        `$destination = '${escapePowerShell(destination)}'`,
        'Compress-Archive -Path (Join-Path $source \"*\") -DestinationPath $destination -CompressionLevel Optimal -Force',
    ].join('; ');

    await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command]);
}

const outputDirectory = await chooseOutputDirectory();
const releaseNotes = await chooseReleaseNotes();
mkdirSync(outputDirectory, { recursive: true });

const filename = `AITranscriber-update-${version}-${platformName}-${buildType}.zip`;
const destination = path.join(outputDirectory, filename);
const temporaryDestination = path.join(outputDirectory, `.${filename}.tmp.zip`);
const versionFile = path.join(outputDirectory, 'version.json');
const temporaryVersionFile = path.join(outputDirectory, '.version.json.tmp');
const stagingDirectory = mkdtempSync(path.join(os.tmpdir(), 'aitranscriber-update-'));

try {
    verifyReleaseSherpaModels();
    copyPayload(stagingDirectory);
    writeFileSync(
        path.join(stagingDirectory, 'version.json'),
        `${JSON.stringify({ version, notes: releaseNotes }, null, 2)}\n`,
    );
    assertProtectedFilesAreAbsent(stagingDirectory);
    writeFileSync(
        path.join(stagingDirectory, 'update-manifest.json'),
        `${JSON.stringify({
            product: tauriConfig.productName,
            version,
            target: platformName,
            buildType,
            extractInto: 'AITranscriber installation directory',
            requiresAppShutdown: true,
            protectedPaths: [
                '.git',
                '.git-broken',
                '.env',
                'database/database.sqlite',
                'storage',
                'whisper',
            ],
            serverApiPath: '/api/transcribe/update/zipfile',
            payload,
        }, null, 2)}\n`,
    );

    rmSync(temporaryDestination, { force: true });
    await zipDirectory(stagingDirectory, temporaryDestination);
    rmSync(destination, { force: true });
    renameSync(temporaryDestination, destination);

    writeFileSync(
        temporaryVersionFile,
        `${JSON.stringify({ version, notes: releaseNotes }, null, 2)}\n`,
    );
    rmSync(versionFile, { force: true });
    renameSync(temporaryVersionFile, versionFile);

    console.log(`Update ZIP created: ${destination}`);
    console.log(`Version metadata updated: ${versionFile}`);
    console.log('Excluded from update ZIP: .git/, .git-broken/, .env, database/database.sqlite, storage/, whisper/');
    console.log('Server delivery path: /api/transcribe/update/zipfile');
} finally {
    rmSync(stagingDirectory, { recursive: true, force: true });
    rmSync(temporaryDestination, { force: true });
    rmSync(temporaryVersionFile, { force: true });
}
