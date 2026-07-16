<?php

namespace App\Services\Config;

class TranscriptionProviderCatalog
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly LicenseSettings $license,
    ) {}

    public function speechToTextProvider(): string
    {
        $provider = $this->settings->get(AppSettingKey::SPEECH_TO_TEXT_PROVIDER);
        $providers = $this->transcriptionProviderOptions();

        if (is_string($provider) && isset($providers[$provider])) {
            return $provider;
        }

        return (string) (array_key_first($providers) ?? '');
    }

    public function setSpeechToTextProvider(string $provider): void
    {
        $this->settings->set(AppSettingKey::SPEECH_TO_TEXT_PROVIDER, trim($provider));
    }

    public function speechToTextModel(?string $provider = null): string
    {
        $provider = $provider ?: $this->speechToTextProvider();
        $model = $this->settings->get(AppSettingKey::SPEECH_TO_TEXT_MODEL);
        $models = $this->transcriptionModelOptions($provider);

        if (is_string($model) && isset($models[$model])) {
            return $model;
        }

        return (string) (array_key_first($models) ?? '');
    }

    public function setSpeechToTextModel(string $model): void
    {
        $this->settings->set(AppSettingKey::SPEECH_TO_TEXT_MODEL, trim($model));
    }

    /**
     * @return array<string, array{provider: string, name: string, models: array<int, array<string, mixed>>}>
     */
    public function transcriptionProviderOptions(): array
    {
        $status = $this->license->licenseStatus();

        if (($status['apis']['transcribe']['allowed'] ?? true) !== true) {
            return [];
        }

        $providers = $status['providers']['transcription'] ?? [];

        if (! is_array($providers)) {
            return [];
        }

        $options = [];

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            if (! ($provider['configured'] ?? false) || ! ($provider['enabled'] ?? false) || ! ($provider['connected'] ?? false)) {
                continue;
            }

            $key = (string) ($provider['provider'] ?? '');

            if ($key === '') {
                continue;
            }

            $models = is_array($provider['models'] ?? null) ? $provider['models'] : [];

            if ($models === []) {
                continue;
            }

            $options[$key] = [
                'provider' => $key,
                'name' => (string) ($provider['name'] ?? ucfirst($key)),
                'models' => array_values(array_filter($models, 'is_array')),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, array{id: string, label: string, default_language_code: string, languages: array<int, array<string, string>>}>
     */
    public function transcriptionModelOptions(?string $provider = null): array
    {
        $provider = $provider ?: $this->speechToTextProvider();
        $providerConfig = $this->transcriptionProviderOptions()[$provider] ?? null;

        if (! is_array($providerConfig)) {
            return [];
        }

        $models = [];

        foreach ($providerConfig['models'] as $model) {
            $id = (string) ($model['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $models[$id] = [
                'id' => $id,
                'label' => (string) ($model['label'] ?? $id),
                'default_language_code' => (string) ($model['default_language_code'] ?? ''),
                'languages' => $this->normalizeLanguageOptions($model['languages'] ?? []),
            ];
        }

        return $models;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function speechToTextLanguageOptions(?string $provider = null, ?string $model = null): array
    {
        $provider = $provider ?: $this->speechToTextProvider();
        $model = $model ?: $this->speechToTextModel($provider);
        $modelConfig = $this->transcriptionModelOptions($provider)[$model] ?? null;
        $languages = is_array($modelConfig) ? ($modelConfig['languages'] ?? []) : [];

        if ($languages !== []) {
            return $languages;
        }

        return [
            ['value' => 'multi', 'label' => 'Multilingual'],
        ];
    }

    /**
     * @return array{provider: string, model: string, language: string}
     */
    public function transcriptionSelection(?string $languageCode = null): array
    {
        $provider = $this->speechToTextProvider();
        $model = $this->speechToTextModel($provider);
        $languages = $this->speechToTextLanguageOptions($provider, $model);
        $modelConfig = $this->transcriptionModelOptions($provider)[$model] ?? [];
        $allowed = collect($languages)->pluck('value')->all();
        $language = trim((string) $languageCode);

        if ($language === '' || ! in_array($language, $allowed, true)) {
            $defaultLanguage = (string) ($modelConfig['default_language_code'] ?? '');
            $language = in_array($defaultLanguage, $allowed, true)
                ? $defaultLanguage
                : (string) ($languages[0]['value'] ?? 'multi');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'language' => $language,
        ];
    }

    private function normalizeLanguageOptions(mixed $languages): array
    {
        if (! is_array($languages)) {
            return [];
        }

        return array_values(array_filter(array_map(
            function ($language): ?array {
                if (! is_array($language)) {
                    return null;
                }

                $code = (string) ($language['code'] ?? '');

                if ($code === '') {
                    return null;
                }

                return [
                    'value' => $code,
                    'label' => (string) ($language['label'] ?? $code),
                ];
            },
            $languages,
        )));
    }
}
