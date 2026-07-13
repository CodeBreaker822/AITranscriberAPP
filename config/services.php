<?php

return [

    'http' => [
        'ca_bundle' => env('AI_TRANSCRIBER_CA_BUNDLE', base_path('php/extras/ssl/cacert.pem')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'transcription_api' => [
        'base_url' => env('TRANSCRIPTION_API_BASE_URL', 'https://dilgaims.site/api'),
        'timeout' => env('TRANSCRIPTION_API_TIMEOUT', 1800),
        'max_upload_bytes' => env('TRANSCRIPTION_API_MAX_UPLOAD_BYTES', 268435456),
    ],

    'audio' => [
        'chunk_seconds' => env('AI_TRANSCRIBER_AUDIO_CHUNK_SECONDS', 60),
    ],

    'silero_vad' => [
        'binary' => env('SILERO_VAD_BINARY'),
        'threshold' => env('SILERO_VAD_THRESHOLD', 0.5),
        'min_speech_ms' => env('SILERO_VAD_MIN_SPEECH_MS', 250),
        'min_silence_ms' => env('SILERO_VAD_MIN_SILENCE_MS', 500),
        'speech_pad_ms' => env('SILERO_VAD_SPEECH_PAD_MS', 80),
        'timeout' => env('SILERO_VAD_TIMEOUT', 30),
    ],

    'upload_prepare' => [
        'process_concurrency' => env('UPLOAD_PREPARE_PROCESS_CONCURRENCY', 2),
    ],

    'resources' => [
        'logical_processors' => env('AI_TRANSCRIBER_LOGICAL_PROCESSORS', env('NUMBER_OF_PROCESSORS', 0)),
        'total_memory_mb' => env('AI_TRANSCRIBER_TOTAL_MEMORY_MB', 0),
        'available_memory_mb' => env('AI_TRANSCRIBER_AVAILABLE_MEMORY_MB', 0),
        'gpu_available' => env('AI_TRANSCRIBER_GPU_AVAILABLE', false),
        'gpu_name' => env('AI_TRANSCRIBER_GPU_NAME', ''),
        'gpu_vram_mb' => env('AI_TRANSCRIBER_GPU_VRAM_MB', 0),
    ],

    'speaker_diarization' => [
        'model_directory' => env('SHERPA_DIARIZATION_MODEL_DIRECTORY'),
        'segmentation_url' => env('SHERPA_DIARIZATION_SEGMENTATION_URL', 'https://github.com/k2-fsa/sherpa-onnx/releases/download/speaker-segmentation-models/sherpa-onnx-pyannote-segmentation-3-0.tar.bz2'),
        'embedding_url' => env('SHERPA_DIARIZATION_EMBEDDING_URL', 'https://github.com/k2-fsa/sherpa-onnx/releases/download/speaker-recongition-models/nemo_en_titanet_small.onnx'),
        'download_timeout' => env('SHERPA_DIARIZATION_DOWNLOAD_TIMEOUT', 1800),
        'timeout' => env('SHERPA_DIARIZATION_TIMEOUT', 900),
        'threads' => env('SHERPA_DIARIZATION_THREADS', 2),
        'cluster_threshold' => env('SHERPA_DIARIZATION_CLUSTER_THRESHOLD', 0.9),
        'match_threshold' => env('SHERPA_SPEAKER_MATCH_THRESHOLD', 0.6),
        'max_speakers' => env('SHERPA_SPEAKER_MAX_TRACKED', 16),
    ],

    'whisper' => [
        'binary' => env('AI_TRANSCRIBER_EXECUTABLE'),
        'model' => env('WHISPER_MODEL_PATH'),
        'model_directory' => env('WHISPER_MODEL_DIRECTORY'),
        'model_url' => env('WHISPER_MODEL_URL', 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q8_0.bin?download=true'),
        'fallback_model_url' => env('WHISPER_FALLBACK_MODEL_URL', 'https://hf-mirror.com/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q8_0.bin'),
        'model_base_url' => env('WHISPER_MODEL_BASE_URL', 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main'),
        'fallback_model_base_url' => env('WHISPER_FALLBACK_MODEL_BASE_URL', 'https://hf-mirror.com/ggerganov/whisper.cpp/resolve/main'),
        'model_sha1' => env('WHISPER_MODEL_SHA1', '01bf15bedffe9f39d65c1b6ff9b687ea91f59e0e'),
        'model_min_bytes' => env('WHISPER_MODEL_MIN_BYTES'),
        'download_timeout' => env('WHISPER_MODEL_DOWNLOAD_TIMEOUT', 3600),
        'timeout' => env('WHISPER_TRANSCRIPTION_TIMEOUT', 1800),
        'threads' => env('AI_TRANSCRIBER_WHISPER_THREADS', 2),
        'memory_budget_mb' => env('AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB', 0),
        'gpu_vram_budget_mb' => env('AI_TRANSCRIBER_WHISPER_GPU_VRAM_BUDGET_MB', 0),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
