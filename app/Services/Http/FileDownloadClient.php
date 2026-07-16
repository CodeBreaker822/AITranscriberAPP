<?php

namespace App\Services\Http;

use RuntimeException;

class FileDownloadClient
{
    public function __construct(private readonly TrustedHttpClient $http)
    {
    }

    /**
     * @param  array{
     *     hash_algorithm?: string,
     *     timeout?: int,
     *     user_agent?: string,
     *     progress_min_bytes?: int,
     *     progress_offset?: int,
     *     progress_total?: int,
     *     progress?: callable(array<string, mixed>): void,
     *     cancelled?: callable(): bool
     * }  $options
     * @return array{
     *     successful: bool,
     *     error: string,
     *     hash: string,
     *     received_bytes: int,
     *     status: int,
     *     effective_url: string,
     *     curl_code: int,
     *     curl_error: string
     * }
     */
    public function download(string $url, string $destinationPath, array $options = []): array
    {
        if (str_starts_with(strtolower($url), 'file://')) {
            return $this->copyLocalFile($url, $destinationPath, $options);
        }

        return $this->downloadRemoteFile($url, $destinationPath, $options);
    }

    public function caBundlePath(): string
    {
        return $this->http->caBundlePath();
    }

    private function downloadRemoteFile(string $url, string $destinationPath, array $options): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('The bundled PHP cURL extension is unavailable.');
        }

        $destination = fopen($destinationPath, 'wb');

        if ($destination === false) {
            throw new RuntimeException('The download file could not be opened.');
        }

        $hash = hash_init((string) ($options['hash_algorithm'] ?? 'sha256'));
        $receivedBytes = 0;
        $lastReportedBytes = 0;
        $progress = $options['progress'] ?? static fn (array $event): null => null;
        $cancelled = $options['cancelled'] ?? static fn (): bool => false;
        $progressMinBytes = (int) ($options['progress_min_bytes'] ?? 1024 * 1024);
        $progressOffset = (int) ($options['progress_offset'] ?? 0);
        $progressTotal = (int) ($options['progress_total'] ?? 0);
        $handle = curl_init($url);

        if ($handle === false) {
            fclose($destination);

            throw new RuntimeException('The download request could not be initialized.');
        }

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 1800),
            CURLOPT_CAINFO => $this->http->caBundlePath(),
            CURLOPT_USERAGENT => (string) ($options['user_agent'] ?? 'AITranscriber Installer'),
            CURLOPT_FAILONERROR => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($destination, $hash, &$receivedBytes, &$lastReportedBytes, $progressMinBytes, $progressOffset, $progressTotal, $progress, $cancelled): int {
                if ($cancelled()) {
                    return 0;
                }

                $length = strlen($chunk);

                if (fwrite($destination, $chunk) === false) {
                    return 0;
                }

                hash_update($hash, $chunk);
                $receivedBytes += $length;
                $downloadTotal = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
                $totalBytes = $progressTotal > 0 ? $progressTotal : max(0, $downloadTotal);

                if (($receivedBytes - $lastReportedBytes) >= $progressMinBytes || ($downloadTotal > 0 && $receivedBytes >= $downloadTotal)) {
                    $lastReportedBytes = $receivedBytes;
                    $progress([
                        'type' => 'progress',
                        'received_bytes' => $progressOffset + $receivedBytes,
                        'total_bytes' => $totalBytes,
                    ]);
                }

                return $length;
            },
        ]);

        $executed = curl_exec($handle);
        $curlError = trim(curl_error($handle));
        $curlCode = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);
        fclose($destination);

        $error = $cancelled()
            ? 'Download canceled.'
            : ($curlError !== '' ? $curlError : "HTTP {$status}");
        $successful = $executed === true && ($status === 0 || ($status >= 200 && $status < 300));

        if (! $successful) {
            @unlink($destinationPath);
        }

        return [
            'successful' => $successful,
            'error' => $successful ? '' : $error,
            'hash' => $successful ? hash_final($hash) : '',
            'received_bytes' => $receivedBytes,
            'status' => $status,
            'effective_url' => $effectiveUrl,
            'curl_code' => $curlCode,
            'curl_error' => $curlError !== '' ? $curlError : $error,
        ];
    }

    private function copyLocalFile(string $url, string $destinationPath, array $options): array
    {
        $sourcePath = $this->localFilePathFromUrl($url);

        if ($sourcePath === null || ! is_file($sourcePath)) {
            @unlink($destinationPath);

            return $this->failedLocalResult($url, 'Local file does not exist.');
        }

        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'wb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new RuntimeException('The download file could not be opened.');
        }

        $hash = hash_init((string) ($options['hash_algorithm'] ?? 'sha256'));
        $receivedBytes = 0;
        $totalBytes = max(0, (int) filesize($sourcePath));
        $progress = $options['progress'] ?? static fn (array $event): null => null;
        $cancelled = $options['cancelled'] ?? static fn (): bool => false;
        $progressOffset = (int) ($options['progress_offset'] ?? 0);
        $progressTotal = (int) ($options['progress_total'] ?? $totalBytes);

        try {
            while (! feof($source)) {
                if ($cancelled()) {
                    @unlink($destinationPath);

                    return [
                        ...$this->failedLocalResult($url, 'Download canceled.'),
                        'received_bytes' => $receivedBytes,
                    ];
                }

                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    @unlink($destinationPath);

                    return [
                        ...$this->failedLocalResult($url, 'Local file could not be read.'),
                        'received_bytes' => $receivedBytes,
                    ];
                }

                if ($chunk === '') {
                    continue;
                }

                if (fwrite($destination, $chunk) === false) {
                    @unlink($destinationPath);

                    return [
                        ...$this->failedLocalResult($url, 'Local file could not be copied.'),
                        'received_bytes' => $receivedBytes,
                    ];
                }

                hash_update($hash, $chunk);
                $receivedBytes += strlen($chunk);
                $progress([
                    'type' => 'progress',
                    'received_bytes' => $progressOffset + $receivedBytes,
                    'total_bytes' => $progressTotal,
                ]);
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        return [
            'successful' => true,
            'error' => '',
            'hash' => hash_final($hash),
            'received_bytes' => $receivedBytes,
            'status' => 0,
            'effective_url' => $url,
            'curl_code' => 0,
            'curl_error' => '',
        ];
    }

    /**
     * @return array{successful: false, error: string, hash: string, received_bytes: int, status: int, effective_url: string, curl_code: int, curl_error: string}
     */
    private function failedLocalResult(string $url, string $error): array
    {
        return [
            'successful' => false,
            'error' => $error,
            'hash' => '',
            'received_bytes' => 0,
            'status' => 0,
            'effective_url' => $url,
            'curl_code' => 0,
            'curl_error' => $error,
        ];
    }

    private function localFilePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = rawurldecode($path);

        if (preg_match('/^\/[A-Za-z]:\//', $path) === 1) {
            $path = ltrim($path, '/');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
