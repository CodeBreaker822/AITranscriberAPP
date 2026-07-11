import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFileSync, cpSync, existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runPhp = path.join(projectRoot, 'scripts', 'run-php.mjs');
const vite = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js');
const emptyBuild = process.argv[2] === 'empty';
const envPath = path.join(projectRoot, '.env');
const envFile = existsSync(envPath) ? readFileSync(envPath, 'utf8') : '';
const tauriConfig = JSON.parse(
    readFileSync(path.join(projectRoot, 'src-tauri', 'tauri.conf.json'), 'utf8'),
);
const envValue = (key) => {
    if (Object.prototype.hasOwnProperty.call(process.env, key)) {
        return process.env[key];
    }

    const match = envFile.match(new RegExp(`^\\s*${key}\\s*=\\s*(.*)$`, 'm'));

    return match ? match[1].trim().replace(/^['"]|['"]$/g, '') : '';
};
const envFlag = (key) => ['1', 'true', 'yes', 'on'].includes(String(envValue(key)).toLowerCase());
const appLogoOnly = envFlag('APP_LOGO_ONLY');
const privateBrandingDirectory = path.normalize('branding').toLowerCase();
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

function verifyBundledSherpaModels() {
    for (const model of bundledSherpaModels) {
        const modelPath = path.join(projectRoot, 'sherpa', 'models', model.file);

        if (!existsSync(modelPath) || statSync(modelPath).size !== model.bytes) {
            throw new Error(`Bundled Sherpa model is missing or incomplete: ${modelPath}`);
        }

        const digest = createHash('sha256').update(readFileSync(modelPath)).digest('hex');
        if (digest !== model.sha256) {
            throw new Error(`Bundled Sherpa model checksum failed: ${modelPath}`);
        }
    }
}

function prepareVulkanLoader() {
    const sdk = String(process.env.VULKAN_SDK || '').trim();
    const windowsDirectory = String(process.env.SystemRoot || process.env.WINDIR || '').trim();
    const candidates = [
        sdk ? path.join(sdk, 'Bin', 'vulkan-1.dll') : '',
        windowsDirectory ? path.join(windowsDirectory, 'System32', 'vulkan-1.dll') : '',
    ];
    const source = candidates.find((candidate) => candidate && existsSync(candidate));

    if (!source) {
        return;
    }

    const destination = path.join(projectRoot, 'src-tauri', 'target', 'release', 'vulkan-1.dll');
    mkdirSync(path.dirname(destination), { recursive: true });
    copyFileSync(source, destination);
}

function preparePackagedPublicDirectory() {
    const sourceDirectory = path.join(projectRoot, 'public');
    const destinationDirectory = path.join(projectRoot, 'build', 'tauri', 'public');

    rmSync(destinationDirectory, { recursive: true, force: true });
    mkdirSync(destinationDirectory, { recursive: true });
    cpSync(sourceDirectory, destinationDirectory, {
        recursive: true,
        force: true,
        filter: (sourcePath) => {
            if (!appLogoOnly) {
                return true;
            }

            const relativePath = path.relative(sourceDirectory, sourcePath);

            if (!relativePath || relativePath.startsWith('..') || path.isAbsolute(relativePath)) {
                return true;
            }

            const normalizedRelativePath = path.normalize(relativePath).toLowerCase();

            return normalizedRelativePath !== privateBrandingDirectory
                && !normalizedRelativePath.startsWith(`${privateBrandingDirectory}${path.sep}`);
        },
    });
}

function run(command, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd: projectRoot,
            stdio: 'inherit',
            windowsHide: true,
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

try {
    verifyBundledSherpaModels();
    prepareVulkanLoader();
    rmSync(path.join(projectRoot, 'public', 'hot'), { force: true });
    await run(process.execPath, [runPhp, 'artisan', 'app:build-vad-cli']);
    await run(process.execPath, [
        runPhp,
        'artisan',
        emptyBuild ? 'app:prepare-tauri-empty-build' : 'app:prepare-tauri-build',
    ]);
    await run(process.execPath, [vite, 'build']);
    const buildMetadataDirectory = path.join(projectRoot, 'build', 'tauri');
    mkdirSync(buildMetadataDirectory, { recursive: true });
    preparePackagedPublicDirectory();
    writeFileSync(
        path.join(buildMetadataDirectory, 'version.json'),
        `${JSON.stringify({
            version: tauriConfig.version,
            notes: `AITranscriber ${tauriConfig.version} update.`,
        }, null, 2)}\n`,
    );
} catch (error) {
    console.error(`Desktop build preparation failed: ${error.message}`);
    process.exitCode = 1;
}
