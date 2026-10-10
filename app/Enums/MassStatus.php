<?php

declare(strict_types=1);

namespace App\Enums;

enum MassStatus: string
{
    case Fresh = 'fresh';
    case Reduced = 'reduced';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public static function worst(self $first, self $second): self
    {
        return $second->severity() > $first->severity() ? $second : $first;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Fresh => 1,
            self::Reduced => 2,
            self::Critical => 3,
        };
    }
}
