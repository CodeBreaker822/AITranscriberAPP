<?php

namespace App\Services\Config;

final class AppSettingKey
{
    public const BASE_URL = 'transcription_api.base_url';

    public const LICENSE_KEY = 'transcription_api.license_key';

    public const LICENSE_STATUS = 'transcription_api.license_status';

    public const SPEECH_TO_TEXT_PROVIDER = 'speech_to_text.provider';

    public const SPEECH_TO_TEXT_MODEL = 'speech_to_text.model';

    public const RESOURCE_MODE = 'resource.mode';

    public const RESOURCE_CPU_THREADS = 'resource.cpu_threads';

    public const RESOURCE_MEMORY_BUDGET_MB = 'resource.memory_budget_mb';

    public const RESOURCE_GPU_VRAM_BUDGET_MB = 'resource.gpu_vram_budget_mb';
}
