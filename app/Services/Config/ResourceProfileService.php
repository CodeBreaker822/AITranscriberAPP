<?php

namespace App\Services\Config;

class ResourceProfileService
{
    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * @return array{mode: string, cpu_threads: int, memory_budget_mb: int, gpu_available: bool, gpu_name: string, gpu_vram_mb: int, gpu_vram_budget_mb: int, auto_cpu_threads: int, auto_memory_budget_mb: int, auto_gpu_vram_budget_mb: int, max_cpu_threads: int, max_memory_budget_mb: int, max_gpu_vram_budget_mb: int, total_memory_mb: int, available_memory_mb: int}
     */
    public function resourceProfile(): array
    {
        $maxCpuThreads = $this->hardwareCpuThreadLimit();
        $maxMemoryBudgetMb = $this->hardwareMemoryLimitMb();
        $availableMemoryMb = max(0, (int) config('services.resources.available_memory_mb', 0));
        $gpuAvailable = filter_var(config('services.resources.gpu_available', false), FILTER_VALIDATE_BOOL);
        $gpuName = $gpuAvailable ? trim((string) config('services.resources.gpu_name', '')) : '';
        $maxGpuVramBudgetMb = $gpuAvailable ? max(0, (int) config('services.resources.gpu_vram_mb', 0)) : 0;
        $autoCpuThreads = $this->clampInt((int) config('services.whisper.threads', 2), 1, $maxCpuThreads);
        $autoMemoryBudgetMb = $this->clampMemoryBudget((int) config('services.whisper.memory_budget_mb', 0), $maxMemoryBudgetMb);
        $autoGpuVramBudgetMb = $this->clampGpuVramBudget((int) config('services.whisper.gpu_vram_budget_mb', 0), $maxGpuVramBudgetMb);
        $mode = $this->settings->get(AppSettingKey::RESOURCE_MODE, 'auto') === 'manual' ? 'manual' : 'auto';
        $manualCpuThreads = $this->clampInt((int) $this->settings->get(AppSettingKey::RESOURCE_CPU_THREADS, (string) $autoCpuThreads), 1, $maxCpuThreads);
        $manualMemoryBudgetMb = $this->clampMemoryBudget((int) $this->settings->get(AppSettingKey::RESOURCE_MEMORY_BUDGET_MB, (string) $autoMemoryBudgetMb), $maxMemoryBudgetMb);
        $manualGpuVramBudgetMb = $this->clampGpuVramBudget((int) $this->settings->get(AppSettingKey::RESOURCE_GPU_VRAM_BUDGET_MB, (string) $autoGpuVramBudgetMb), $maxGpuVramBudgetMb);

        return [
            'mode' => $mode,
            'cpu_threads' => $mode === 'manual' ? $manualCpuThreads : $autoCpuThreads,
            'memory_budget_mb' => $mode === 'manual' ? $manualMemoryBudgetMb : $autoMemoryBudgetMb,
            'gpu_available' => $gpuAvailable && $maxGpuVramBudgetMb > 0,
            'gpu_name' => $gpuName,
            'gpu_vram_mb' => $maxGpuVramBudgetMb,
            'gpu_vram_budget_mb' => $mode === 'manual' ? $manualGpuVramBudgetMb : $autoGpuVramBudgetMb,
            'auto_cpu_threads' => $autoCpuThreads,
            'auto_memory_budget_mb' => $autoMemoryBudgetMb,
            'auto_gpu_vram_budget_mb' => $autoGpuVramBudgetMb,
            'max_cpu_threads' => $maxCpuThreads,
            'max_memory_budget_mb' => $maxMemoryBudgetMb,
            'max_gpu_vram_budget_mb' => $maxGpuVramBudgetMb,
            'total_memory_mb' => $maxMemoryBudgetMb,
            'available_memory_mb' => $availableMemoryMb,
        ];
    }

    public function setResourceProfile(string $mode, int $cpuThreads, int $memoryBudgetMb, int $gpuVramBudgetMb = 0): void
    {
        $mode = $mode === 'manual' ? 'manual' : 'auto';
        $maxCpuThreads = $this->hardwareCpuThreadLimit();
        $maxMemoryBudgetMb = $this->hardwareMemoryLimitMb();
        $maxGpuVramBudgetMb = $this->hardwareGpuVramLimitMb();

        $this->settings->set(AppSettingKey::RESOURCE_MODE, $mode);
        $this->settings->set(AppSettingKey::RESOURCE_CPU_THREADS, (string) $this->clampInt($cpuThreads, 1, $maxCpuThreads));
        $this->settings->set(AppSettingKey::RESOURCE_MEMORY_BUDGET_MB, (string) $this->clampMemoryBudget($memoryBudgetMb, $maxMemoryBudgetMb));
        $this->settings->set(AppSettingKey::RESOURCE_GPU_VRAM_BUDGET_MB, (string) $this->clampGpuVramBudget($gpuVramBudgetMb, $maxGpuVramBudgetMb));
    }

    private function hardwareCpuThreadLimit(): int
    {
        return max(
            1,
            (int) config('services.resources.logical_processors', 0),
            (int) config('services.whisper.threads', 2),
        );
    }

    private function hardwareMemoryLimitMb(): int
    {
        $totalMemoryMb = max(0, (int) config('services.resources.total_memory_mb', 0));

        if ($totalMemoryMb > 0) {
            return $totalMemoryMb;
        }

        return max(0, (int) config('services.whisper.memory_budget_mb', 0));
    }

    private function hardwareGpuVramLimitMb(): int
    {
        if (! filter_var(config('services.resources.gpu_available', false), FILTER_VALIDATE_BOOL)) {
            return 0;
        }

        return max(0, (int) config('services.resources.gpu_vram_mb', 0));
    }

    private function clampMemoryBudget(int $value, int $maxMemoryBudgetMb): int
    {
        if ($maxMemoryBudgetMb <= 0) {
            return 0;
        }

        return $this->clampInt($value, 1, $maxMemoryBudgetMb);
    }

    private function clampGpuVramBudget(int $value, int $maxGpuVramBudgetMb): int
    {
        if ($maxGpuVramBudgetMb <= 0) {
            return 0;
        }

        return $this->clampInt($value, 0, $maxGpuVramBudgetMb);
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($value, max($min, $max)));
    }
}
