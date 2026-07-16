<?php

namespace App\Services\Config;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SettingsStore
{
    private ?bool $settingsTableReady = null;

    /** @var array<string, string|null>|null */
    private ?array $settingsValues = null;

    public function storageIsReady(): bool
    {
        return $this->settingsTableExists();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (! $this->settingsTableExists()) {
            return $default;
        }

        $values = $this->settingsValues();

        if (! array_key_exists($key, $values)) {
            return $default;
        }

        return $values[$key] === null ? $default : (string) $values[$key];
    }

    public function set(string $key, ?string $value): void
    {
        if (! $this->settingsTableExists()) {
            return;
        }

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'is_encrypted' => true],
        );

        if ($this->settingsValues !== null) {
            $this->settingsValues[$key] = $value;
        }
    }

    private function settingsTableExists(): bool
    {
        if ($this->settingsTableReady !== null) {
            return $this->settingsTableReady;
        }

        try {
            return $this->settingsTableReady = Schema::hasTable('app_settings');
        } catch (Throwable) {
            return $this->settingsTableReady = false;
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function settingsValues(): array
    {
        if ($this->settingsValues !== null) {
            return $this->settingsValues;
        }

        $this->settingsValues = [];

        try {
            AppSetting::query()
                ->get(['key', 'value'])
                ->each(function (AppSetting $setting): void {
                    $this->settingsValues[(string) $setting->key] = $setting->value === null
                        ? null
                        : (string) $setting->value;
                });
        } catch (Throwable) {
            $this->settingsValues = [];
        }

        return $this->settingsValues;
    }
}
