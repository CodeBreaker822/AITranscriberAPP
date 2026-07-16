<?php

namespace App\Services\Speech;

use App\Exceptions\SpeechToTextException;

class OfflineWhisperBinaryLocator
{
    public function binaryPath(): string
    {
        return $this->findBinaryPath()
            ?? throw new SpeechToTextException('Offline Whisper is available in the Tauri desktop app only.');
    }

    public function findBinaryPath(): ?string
    {
        $configured = trim((string) config('services.whisper.binary', ''));
        $binaryName = 'aitranscriber.exe';
        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            base_path('src-tauri/target/release/'.$binaryName),
            base_path('src-tauri/target/debug/'.$binaryName),
        ]));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
