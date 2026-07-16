<?php

namespace App\Services\Speech;

use App\Exceptions\SpeechToTextException;
use App\Services\Support\ServiceUserMessage;
use Illuminate\Http\UploadedFile;
use SplFileInfo;

class OfflineWhisperAudioResolver
{
    public function path(UploadedFile|string|SplFileInfo $audio): string
    {
        $path = match (true) {
            $audio instanceof UploadedFile => $audio->getRealPath(),
            $audio instanceof SplFileInfo => $audio->getRealPath(),
            default => $audio,
        };

        if (! is_string($path) || ! is_file($path)) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return $path;
    }
}
