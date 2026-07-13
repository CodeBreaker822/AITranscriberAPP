<?php

namespace App\Services\AudioChunk;

final class AudioChunkIngestionResult
{
    public const SAVED = 'saved';
    public const SKIPPED = 'skipped';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly ?array $data = null,
    ) {}

    public static function saved(array $data): self
    {
        return new self(self::SAVED, 'saved', $data);
    }

    public static function skipped(array $data): self
    {
        return new self(self::SKIPPED, 'skipped', $data);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public function toResponsePayload(): array
    {
        $payload = ['message' => $this->message];

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }
}
