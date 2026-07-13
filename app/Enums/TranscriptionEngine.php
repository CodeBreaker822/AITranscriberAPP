<?php

namespace App\Enums;

enum TranscriptionEngine: string
{
    case Online = 'online';
    case Offline = 'offline';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $engine): string => $engine->value,
            self::cases(),
        );
    }

    public static function fromOption(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::Online)
            : self::Online;
    }
}
