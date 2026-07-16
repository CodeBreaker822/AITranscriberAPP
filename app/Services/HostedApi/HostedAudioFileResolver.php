<?php

namespace App\Services\HostedApi;

use App\Exceptions\SpeechToTextException;
use App\Services\Support\ServiceUserMessage;
use Illuminate\Http\UploadedFile;
use SplFileInfo;

class HostedAudioFileResolver
{
    /**
     * @return array{path: string, name: string, size: int}
     */
    public function resolve(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
                'size' => max(0, (int) $audio->getSize()),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
                'size' => max(0, (int) filesize($path)),
            ];
        }

        if (! is_file($audio)) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
            'size' => max(0, (int) filesize($audio)),
        ];
    }

    /**
     * @param  array{path: string, name: string, size: int}  $file
     * @return resource
     */
    public function openStream(array $file)
    {
        $stream = fopen($file['path'], 'rb');

        if ($stream === false) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return $stream;
    }

    /**
     * @param  array<int, resource>  $streams
     */
    public function closeStreams(array $streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
