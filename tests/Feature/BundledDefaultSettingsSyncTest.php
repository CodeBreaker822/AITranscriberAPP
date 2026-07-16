<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\Config\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BundledDefaultSettingsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_copies_bundled_database_license_when_local_database_is_empty(): void
    {
        $bundledDatabasePath = storage_path('framework/testing/bundled-'.Str::uuid().'.sqlite');

        try {
            File::ensureDirectoryExists(dirname($bundledDatabasePath));
            File::delete($bundledDatabasePath);

            $bundled = new \PDO('sqlite:'.$bundledDatabasePath);
            $bundled->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $bundled->exec('create table app_settings (id integer primary key autoincrement, key varchar not null, value text null, is_encrypted tinyint(1) not null default 0, created_at datetime null, updated_at datetime null)');

            $defaultLicense = AppSetting::query()->make([
                'key' => AppSettingsService::LICENSE_KEY,
                'value' => 'bundled-license-key',
                'is_encrypted' => true,
            ]);

            $bundledStatement = $bundled->prepare('insert into app_settings (key, value, is_encrypted, created_at, updated_at) values (?, ?, ?, ?, ?)');
            $bundledStatement->execute([
                AppSettingsService::LICENSE_KEY,
                $defaultLicense->getAttributes()['value'],
                1,
                now()->toDateTimeString(),
                now()->toDateTimeString(),
            ]);
            $bundledStatement = null;
            $bundled = null;

            AppSetting::query()->updateOrCreate(
                ['key' => AppSettingsService::LICENSE_KEY],
                ['value' => '', 'is_encrypted' => true],
            );

            $this->assertFalse(app(AppSettingsService::class)->hasLicenseKey());

            Artisan::call('app:sync-bundled-default-settings', [
                '--bundled-database' => $bundledDatabasePath,
            ]);

            $this->assertSame('bundled-license-key', app(AppSettingsService::class)->licenseKey());
        } finally {
            File::delete($bundledDatabasePath);
        }
    }
}
