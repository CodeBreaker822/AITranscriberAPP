<?php

namespace App\Services\AudioChunk;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class UploadedAudioBatchPreparationService
{
    public function prepare(array $validated): array
    {
        $sections = array_values($validated['sections']);
        $requestedConcurrency = (int) ($validated['concurrency'] ?? count($sections));
        $processConcurrencyLimit = max(1, (int) config('services.upload_prepare.process_concurrency', 2));
        $concurrency = max(1, min(
            $requestedConcurrency,
            count($sections),
            $processConcurrencyLimit,
        ));

        Log::info('Uploaded audio batch preparation started.', [
            'section_count' => count($sections),
            'requested_concurrency' => $requestedConcurrency,
            'process_concurrency_limit' => $processConcurrencyLimit,
            'effective_concurrency' => $concurrency,
        ]);

        $pending = array_map(function (array $section, int $index) use ($validated): array {
            return [
                'index' => $index,
                'payload' => [
                    'upload_session_id' => $validated['upload_session_id'],
                    'user_id' => (int) ($validated['user_id'] ?? 1),
                    'category_name' => trim((string) $validated['category_name']),
                    ...$section,
                ],
            ];
        }, $sections, array_keys($sections));
        $running = [];
        $prepared = [];

        try {
            while ($pending !== [] || $running !== []) {
                while (count($running) < $concurrency && $pending !== []) {
                    $job = array_shift($pending);
                    $child = $this->prepareSectionProcess($job['payload']);
                    $process = $child['process'];
                    $process->start();
                    $running[] = [
                        'index' => $job['index'],
                        'process' => $process,
                        'temp_directory' => $child['temp_directory'],
                    ];
                }

                foreach ($running as $key => $job) {
                    /** @var Process $process */
                    $process = $job['process'];

                    if ($process->isRunning()) {
                        continue;
                    }

                    unset($running[$key]);
                    $output = trim($process->getOutput());
                    $errorOutput = trim($process->getErrorOutput());
                    $successful = $process->isSuccessful();
                    File::deleteDirectory($job['temp_directory']);
                    $payload = json_decode($output, true);

                    if (! $successful || ! is_array($payload)) {
                        $message = is_array($payload) && is_string($payload['message'] ?? null)
                            ? $payload['message']
                            : $errorOutput;

                        throw new RuntimeException($message !== '' ? $message : 'Audio section could not be prepared.');
                    }

                    if (($payload['ok'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
                        throw new RuntimeException((string) ($payload['message'] ?? 'Audio section could not be prepared.'));
                    }

                    $prepared[] = [
                        'index' => (int) $job['index'],
                        ...$payload['data'],
                    ];
                }

                if ($running !== []) {
                    usleep(40_000);
                }
            }
        } catch (RuntimeException $exception) {
            foreach ($running as $job) {
                /** @var Process $process */
                $process = $job['process'];
                $process->stop(0);
                File::deleteDirectory($job['temp_directory']);
            }

            Log::error('Uploaded audio batch preparation failed.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
                'requested_concurrency' => $requestedConcurrency,
                'process_concurrency_limit' => $processConcurrencyLimit,
                'effective_concurrency' => $concurrency,
            ]);

            throw $exception;
        }

        usort($prepared, fn (array $first, array $second): int => $first['index'] <=> $second['index']);
        $prepared = array_map(function (array $item): array {
            unset($item['index']);

            return $item;
        }, $prepared);

        return [
            'message' => 'prepared',
            'data' => $prepared,
            'concurrency' => $concurrency,
            'requested_concurrency' => $requestedConcurrency,
        ];
    }

    /** @return array{process: Process, temp_directory: string} */
    private function prepareSectionProcess(array $payload): array
    {
        $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $phpBinary = is_file(base_path('php/php.exe'))
            ? base_path('php/php.exe')
            : PHP_BINARY;
        $tempDirectory = storage_path('framework/process-temp/upload-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($tempDirectory);
        $process = new Process(
            [
                $phpBinary,
                base_path('artisan'),
                'app:prepare-upload-section',
                '--payload='.$encoded,
            ],
            base_path(),
            [
                'APP_STORAGE_PATH' => storage_path(),
                'APP_PROCESS_TEMP_PATH' => $tempDirectory,
                'DB_DATABASE' => (string) config('database.connections.sqlite.database'),
                'TMP' => $tempDirectory,
                'TEMP' => $tempDirectory,
                'TMPDIR' => $tempDirectory,
            ],
        );
        $process->setTimeout(null);

        return [
            'process' => $process,
            'temp_directory' => $tempDirectory,
        ];
    }
}
