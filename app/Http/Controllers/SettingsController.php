<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\Config\AppSettingsService;
use App\Services\Audio\AudioMemoryService;
use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Transcripts\TranscriptMemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(
        AppSettingsService $settings,
        HostedTranscriptionApiService $api,
        AudioMemoryService $audioMemory,
        TranscriptMemoryService $transcriptMemory,
    ): View {
        $licenseRefreshError = null;

        if ($this->shouldRefreshLicenseCapabilities($settings)) {
            try {
                $this->refreshLicenseCapabilities($settings, $api, $settings->licenseKey() ?? '');
            } catch (SpeechToTextException $exception) {
                $licenseRefreshError = $exception->getMessage();
            }
        }

        $provider = $settings->speechToTextProvider();
        $transcriptionProviders = $settings->transcriptionProviderOptions();

        return view('pages.settings', [
            'apiBaseUrl' => $settings->apiBaseUrl(),
            'hasLicenseKey' => $settings->hasLicenseKey(),
            'licenseKeySuffix' => $settings->licenseKeySuffix(),
            'licenseStatusLabel' => $settings->licenseStatusLabel(),
            'licenseStatusMessage' => $settings->licenseStatusMessage(),
            'licenseRefreshError' => $licenseRefreshError,
            'transcriptionProviders' => $transcriptionProviders,
            'providerPayload' => $this->providerPayload($transcriptionProviders),
            'selectedProvider' => $provider,
            'selectedModel' => $settings->speechToTextModel($provider),
            'resourceProfile' => $settings->resourceProfile(),
            'audioMemory' => $audioMemory->snapshot(),
            'transcriptMemory' => $transcriptMemory->snapshot(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $providers
     * @return array<string, array{models: array<int, array{id: string, label: string}>}>
     */
    private function providerPayload(array $providers): array
    {
        return collect($providers)
            ->mapWithKeys(fn (array $provider, string $key): array => [
                $key => [
                    'models' => collect($provider['models'] ?? [])
                        ->filter(fn ($model): bool => is_array($model) && filled($model['id'] ?? null))
                        ->map(fn (array $model): array => [
                            'id' => (string) $model['id'],
                            'label' => (string) ($model['label'] ?? $model['id']),
                        ])
                        ->values()
                        ->all(),
                ],
            ])
            ->all();
    }

    public function help(): View
    {
        return view('pages.api-key-help', [
            'providers' => [],
        ]);
    }

    public function update(
        Request $request,
        AppSettingsService $settings,
        HostedTranscriptionApiService $api,
    ): RedirectResponse {
        if (! $settings->storageIsReady()) {
            return back()->withErrors([
                'settings' => 'Settings storage is not ready. Please run the database migration first.',
            ]);
        }

        $resourceProfile = $settings->resourceProfile();
        $maxCpuThreads = max(1, (int) $resourceProfile['max_cpu_threads']);
        $maxMemoryBudgetMb = max(0, (int) $resourceProfile['max_memory_budget_mb']);
        $maxGpuVramBudgetMb = max(0, (int) $resourceProfile['max_gpu_vram_budget_mb']);
        $memoryRules = $maxMemoryBudgetMb > 0
            ? ['nullable', 'integer', 'min:1', 'max:'.$maxMemoryBudgetMb]
            : ['nullable', 'integer', 'min:0', 'max:0'];
        $gpuVramRules = $maxGpuVramBudgetMb > 0
            ? ['nullable', 'integer', 'min:0', 'max:'.$maxGpuVramBudgetMb]
            : ['nullable', 'integer', 'min:0', 'max:0'];

        $validated = $request->validate([
            'api_base_url' => ['required', 'string', 'max:255'],
            'license_key' => [$settings->hasLicenseKey() ? 'nullable' : 'required', 'string', 'max:2000'],
            'speech_to_text_provider' => ['nullable', 'string', 'max:80'],
            'speech_to_text_model' => ['nullable', 'string', 'max:120'],
            'resource_mode' => ['nullable', 'string', 'in:auto,manual'],
            'resource_cpu_threads' => ['nullable', 'integer', 'min:1', 'max:'.$maxCpuThreads],
            'resource_memory_budget_mb' => $memoryRules,
            'resource_gpu_vram_budget_mb' => $gpuVramRules,
        ]);

        $settings->setApiBaseUrl((string) $validated['api_base_url']);
        $licenseKey = trim((string) ($validated['license_key'] ?? ''));

        if ($licenseKey !== '') {
            $settings->setLicenseKey($licenseKey);
        } else {
            $licenseKey = $settings->licenseKey() ?? '';
        }

        try {
            $this->refreshLicenseCapabilities($settings, $api, $licenseKey);
        } catch (SpeechToTextException $exception) {
            return back()
                ->withInput()
                ->withErrors(['license_key' => $exception->getMessage()]);
        }

        $this->selectAvailableProviderAndModel(
            $settings,
            (string) ($validated['speech_to_text_provider'] ?? ''),
            (string) ($validated['speech_to_text_model'] ?? ''),
        );
        $settings->setResourceProfile(
            (string) ($validated['resource_mode'] ?? 'auto'),
            (int) ($validated['resource_cpu_threads'] ?? $resourceProfile['auto_cpu_threads']),
            (int) ($validated['resource_memory_budget_mb'] ?? $resourceProfile['auto_memory_budget_mb']),
            (int) ($validated['resource_gpu_vram_budget_mb'] ?? $resourceProfile['auto_gpu_vram_budget_mb']),
        );

        return redirect()
            ->route('settings.edit')
            ->with('status', 'License saved and server capabilities loaded.');
    }

    private function shouldRefreshLicenseCapabilities(AppSettingsService $settings): bool
    {
        return $settings->hasLicenseKey()
            && ($settings->licenseStatus() === [] || $settings->transcriptionProviderOptions() === []);
    }

    private function refreshLicenseCapabilities(
        AppSettingsService $settings,
        HostedTranscriptionApiService $api,
        string $licenseKey,
    ): void {
        $status = $api->licenseStatus($licenseKey);

        $settings->setLicenseStatus($status);
        $this->selectAvailableProviderAndModel($settings);
    }

    private function selectAvailableProviderAndModel(
        AppSettingsService $settings,
        ?string $requestedProvider = null,
        ?string $requestedModel = null,
    ): void
    {
        $providers = $settings->transcriptionProviderOptions();
        $provider = trim((string) ($requestedProvider ?: $settings->speechToTextProvider()));

        if (! isset($providers[$provider])) {
            $provider = (string) (array_key_first($providers) ?? '');
        }

        if ($provider !== '') {
            $settings->setSpeechToTextProvider($provider);
        }

        $models = $settings->transcriptionModelOptions($provider);
        $model = trim((string) ($requestedModel ?: $settings->speechToTextModel($provider)));

        if (! isset($models[$model])) {
            $model = (string) (array_key_first($models) ?? '');
        }

        if ($model !== '') {
            $settings->setSpeechToTextModel($model);
        }
    }
}
