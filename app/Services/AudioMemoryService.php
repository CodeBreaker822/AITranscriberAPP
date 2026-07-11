<?php

namespace App\Services;

use App\Models\AudioChunk;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AudioMemoryService
{
    private const TEMPORARY_AUDIO_DIRECTORIES = [
        'audio-upload-sessions',
        'audio-upload-chunks',
    ];

    public function __construct(private readonly StoredAudioService $storedAudio) {}

    public function snapshot(): array
    {
        $stored = $this->storedAudioSnapshot();
        $temporary = $this->temporaryAudioSnapshot();
        $totalBytes = $stored['bytes'] + $temporary['bytes'];

        return [
            'total' => [
                'bytes' => $totalBytes,
                'formatted_size' => $this->formatBytes($totalBytes),
            ],
            'stored' => array_merge($stored, [
                'formatted_size' => $this->formatBytes($stored['bytes']),
            ]),
            'temporary' => array_merge($temporary, [
                'formatted_size' => $this->formatBytes($temporary['bytes']),
            ]),
        ];
    }

    public function purgeTemporaryAudio(): array
    {
        $before = $this->temporaryAudioSnapshot();

        foreach ($this->temporaryAudioRoots() as $directory) {
            if (! $this->isManagedTemporaryRoot($directory)) {
                continue;
            }

            if (File::isDirectory($directory)) {
                File::deleteDirectory($directory);
            }

            File::ensureDirectoryExists($directory);
        }

        return array_merge($before, [
            'formatted_size' => $this->formatBytes($before['bytes']),
        ]);
    }

    public function purgeStoredAudioRecords(): array
    {
        $before = $this->storedAudioSnapshot();

        if (Schema::hasTable('audio_chunks')) {
            AudioChunk::query()
                ->whereNotNull('audio_path')
                ->pluck('audio_path')
                ->each(fn (string $path) => $this->storedAudio->delete($path));

            AudioChunk::query()
                ->where('file_size_bytes', '>', 0)
                ->update([
                    'audio_blob' => '',
                    'audio_path' => null,
                    'audio_size' => null,
                    'audio_hash' => null,
                    'file_size_bytes' => 0,
                    'mime_type' => null,
                    'updated_at' => now(),
                ]);
        }

        return array_merge($before, [
            'formatted_size' => $this->formatBytes($before['bytes']),
        ]);
    }

    public function purgeAllAudioData(): array
    {
        $before = $this->snapshot();

        $this->purgeTemporaryAudio();
        $this->purgeStoredAudioRecords();

        return [
            'bytes' => $before['total']['bytes'],
            'formatted_size' => $before['total']['formatted_size'],
            'temporary_files' => $before['temporary']['files'],
            'stored_records' => $before['stored']['records'],
        ];
    }

    private function storedAudioSnapshot(): array
    {
        if (! Schema::hasTable('audio_chunks')) {
            return [
                'bytes' => 0,
                'records' => 0,
                'live_records' => 0,
                'upload_records' => 0,
            ];
        }

        $audioRows = AudioChunk::query()
            ->get(['audio_size', 'file_size_bytes', 'original_name']);
        $records = $audioRows
            ->filter(fn (AudioChunk $row): bool => (int) ($row->audio_size ?? $row->file_size_bytes ?? 0) > 0)
            ->count();
        $bytes = $audioRows->sum(fn (AudioChunk $row): int => (int) ($row->audio_size ?? $row->file_size_bytes ?? 0));
        $uploadRecords = $audioRows
            ->filter(fn (AudioChunk $row): bool => preg_match('/^chunk_.*\.wav$/i', (string) $row->original_name) === 1
                && (int) ($row->file_size_bytes ?? 0) > 0)
            ->count();

        return [
            'bytes' => (int) $bytes,
            'records' => $records,
            'live_records' => max(0, $records - $uploadRecords),
            'upload_records' => (int) $uploadRecords,
        ];
    }

    private function temporaryAudioSnapshot(): array
    {
        $bytes = 0;
        $fileCount = 0;
        $sessionCount = 0;

        foreach ($this->temporaryAudioRoots() as $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            $sessionCount += count(File::directories($directory));

            foreach (File::allFiles($directory) as $file) {
                $bytes += $file->getSize();
                $fileCount++;
            }
        }

        return [
            'bytes' => $bytes,
            'files' => $fileCount,
            'sessions' => $sessionCount,
        ];
    }

    private function temporaryAudioRoots(): array
    {
        return array_map(
            fn (string $directory): string => storage_path('app/private/'.$directory),
            self::TEMPORARY_AUDIO_DIRECTORIES,
        );
    }

    private function isManagedTemporaryRoot(string $directory): bool
    {
        $normalizedDirectory = str_replace('\\', '/', $directory);
        $normalizedBase = str_replace('\\', '/', storage_path('app/private/audio-upload'));

        return str_starts_with($normalizedDirectory, $normalizedBase);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes).' B';
    }
}
