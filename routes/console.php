<?php

use App\Services\Config\AppSettingsService;
use App\Services\Speakers\SpeakerDiarizationService;
use App\Services\Audio\UploadedAudioSectionPreparationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:build-vad-cli', function () {
    $manifestPath = base_path('vad-cli/Cargo.toml');
    $targetDirectory = base_path('vad-cli/target/release');
    $bundleDirectory = base_path('build/vad');
    $binaryName = PHP_OS_FAMILY === 'Windows' ? 'vad-cli.exe' : 'vad-cli';
    $binaryPath = $targetDirectory.DIRECTORY_SEPARATOR.$binaryName;

    if (! File::exists($manifestPath)) {
        $this->error("VAD CLI manifest is missing: {$manifestPath}");

        return 1;
    }

    $process = new Process([
        'cargo',
        'build',
        '--manifest-path',
        $manifestPath,
        '--release',
    ], base_path());
    $process->setTimeout(null);
    $process->run(fn ($type, $buffer) => $this->output->write($buffer));

    if (! $process->isSuccessful()) {
        $this->error('VAD CLI build failed.');

        return 1;
    }

    if (! File::exists($binaryPath)) {
        $this->error("VAD CLI binary was not created: {$binaryPath}");

        return 1;
    }

    File::ensureDirectoryExists($bundleDirectory);
    File::copy($binaryPath, $bundleDirectory.DIRECTORY_SEPARATOR.$binaryName);

    foreach (File::glob($targetDirectory.DIRECTORY_SEPARATOR.'*.dll') ?: [] as $dllPath) {
        File::copy($dllPath, $bundleDirectory.DIRECTORY_SEPARATOR.basename($dllPath));
    }

    $this->info('Built VAD CLI.');
    $this->line("Bundled VAD directory: {$bundleDirectory}");

    return 0;
})->purpose('Build the standalone Silero VAD CLI for Tauri packaging.');

Artisan::command('app:prepare-upload-section {--payload=}', function () {
    $payload = (string) $this->option('payload');
    $decoded = json_decode((string) base64_decode($payload, true), true);

    if (! is_array($decoded)) {
        $this->output->write(json_encode([
            'ok' => false,
            'message' => 'Audio section preparation payload was invalid.',
        ]));

        return 1;
    }

    try {
        $data = app(UploadedAudioSectionPreparationService::class)->prepare($decoded);
        $this->output->write(json_encode([
            'ok' => true,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES));

        return 0;
    } catch (Throwable $exception) {
        $this->output->write(json_encode([
            'ok' => false,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES));

        return 1;
    }
})->purpose('Prepare one uploaded audio section for transcription.');

Artisan::command('app:diarize-upload-batch {--payload=}', function () {
    $payload = (string) $this->option('payload');
    $decoded = json_decode((string) base64_decode($payload, true), true);

    if (! is_array($decoded) || ! is_array($decoded['clips'] ?? null)) {
        $this->output->write(json_encode([
            'ok' => false,
            'message' => 'Speaker diarization payload was invalid.',
        ]));

        return 1;
    }

    try {
        $speakerDiarization = app(SpeakerDiarizationService::class);
        $speakerSessionId = trim((string) ($decoded['speaker_session_id'] ?? ''));
        $data = [];

        foreach (array_values($decoded['clips']) as $queueIndex => $clip) {
            if (! is_array($clip)) {
                continue;
            }

            $audioPath = (string) ($clip['audio_path'] ?? '');

            $data[] = [
                'queue_index' => $queueIndex,
                'clip_index' => isset($clip['clip_index']) ? (int) $clip['clip_index'] : null,
                'segments' => $speakerDiarization->diarizeSegments($audioPath, [
                    'clip_start_ms' => (int) ($clip['clip_start_ms'] ?? 0),
                    'speaker_session_id' => $speakerSessionId !== '' ? $speakerSessionId : null,
                ]),
            ];
        }

        $this->output->write(json_encode([
            'ok' => true,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES));

        return 0;
    } catch (Throwable $exception) {
        $this->output->write(json_encode([
            'ok' => false,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES));

        return 1;
    }
})->purpose('Run local Sherpa speaker diarization for an upload batch.');

Artisan::command('app:prepare-tauri-build', function () {
    $sqlitePath = database_path('database.sqlite');
    $sqliteSnapshotPath = base_path('build/tauri/database/database.sqlite');

    File::ensureDirectoryExists(dirname($sqlitePath));

    if (! File::exists($sqlitePath)) {
        File::put($sqlitePath, '');
    }

    Artisan::call('migrate', [
        '--force' => true,
        '--no-interaction' => true,
    ]);

    $deletedCleanChunks = 0;
    $deletedAudioChunks = 0;
    $hasDefaultLicenseKey = false;

    if (Schema::hasTable('clean_transcript_chunks')) {
        $deletedCleanChunks = DB::table('clean_transcript_chunks')->delete();
    }

    if (Schema::hasTable('audio_chunks')) {
        $deletedAudioChunks = DB::table('audio_chunks')->delete();
    }

    if (Schema::hasTable('app_settings')) {
        $hasDefaultLicenseKey = app(AppSettingsService::class)->hasLicenseKey();
    }

    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::statement('VACUUM');
    }

    DB::disconnect();

    File::ensureDirectoryExists(dirname($sqliteSnapshotPath));
    File::copy($sqlitePath, $sqliteSnapshotPath);

    $this->info("Prepared Tauri build database.");
    $this->line("Deleted clean transcript chunks: {$deletedCleanChunks}");
    $this->line("Deleted audio chunks: {$deletedAudioChunks}");
    $this->line('Default license included: '.($hasDefaultLicenseKey ? 'yes' : 'no'));
    $this->line("Bundled SQLite snapshot: {$sqliteSnapshotPath}");
})->purpose('Remove transcript and audio records before packaging the Tauri app.');

Artisan::command('app:sync-bundled-default-settings {--bundled-database=}', function () {
    if (! Schema::hasTable('app_settings')) {
        $this->line('Default settings sync skipped: app_settings table is missing.');

        return 0;
    }

    $settings = app(AppSettingsService::class);

    if ($settings->hasLicenseKey()) {
        $this->line('Default settings sync skipped: local license already exists.');

        return 0;
    }

    $bundledDatabasePath = (string) ($this->option('bundled-database') ?: database_path('database.sqlite'));

    if (! is_file($bundledDatabasePath)) {
        $this->line('Default settings sync skipped: bundled database is missing.');

        return 0;
    }

    $bundled = new PDO('sqlite:'.$bundledDatabasePath);
    $bundled->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hasBundledSettings = $bundled
        ->query("select name from sqlite_master where type = 'table' and name = 'app_settings'")
        ->fetchColumn();

    if (! $hasBundledSettings) {
        $this->line('Default settings sync skipped: bundled app_settings table is missing.');

        return 0;
    }

    $settingKeys = [
        AppSettingsService::BASE_URL,
        AppSettingsService::LICENSE_KEY,
        AppSettingsService::LICENSE_STATUS,
        AppSettingsService::SPEECH_TO_TEXT_PROVIDER,
        AppSettingsService::SPEECH_TO_TEXT_MODEL,
    ];
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $statement = $bundled->prepare(
        "select key, value, is_encrypted from app_settings where key in ({$placeholders}) and value is not null and trim(value) != ''"
    );
    $statement->execute($settingKeys);

    $synced = 0;

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['key'] ?? '') === AppSettingsService::LICENSE_KEY && $settings->hasLicenseKey()) {
            continue;
        }

        $localValue = $settings->get((string) $row['key']);

        if (is_string($localValue) && trim($localValue) !== '') {
            continue;
        }

        if (in_array($row['key'] ?? '', $settingKeys, true)) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'value' => $row['value'],
                    'is_encrypted' => (bool) $row['is_encrypted'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $synced++;
        }
    }

    $this->line("Default settings synced: {$synced}");

    return 0;
})->purpose('Copy bundled default license settings into the writable production database when missing.');

Artisan::command('app:prepare-tauri-empty-build', function () {
    $sqliteSnapshotPath = base_path('build/tauri/database/database.sqlite');

    File::ensureDirectoryExists(dirname($sqliteSnapshotPath));
    File::delete($sqliteSnapshotPath);
    File::put($sqliteSnapshotPath, '');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $sqliteSnapshotPath,
        'database.connections.sqlite.url' => null,
    ]);

    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');

    Artisan::call('migrate', [
        '--database' => 'sqlite',
        '--force' => true,
        '--no-interaction' => true,
    ]);

    if (Schema::connection('sqlite')->hasTable('app_settings')) {
        DB::connection('sqlite')->table('app_settings')->delete();
    }

    DB::connection('sqlite')->statement('VACUUM');
    DB::disconnect('sqlite');

    $this->info('Prepared empty Tauri build database.');
    $this->line("Bundled SQLite snapshot: {$sqliteSnapshotPath}");
    $this->line('Default API keys included: no');
})->purpose('Create a migrated Tauri database without default API keys or user data.');
