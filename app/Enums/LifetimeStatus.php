<?php

declare(strict_types=1);

namespace App\Enums;

enum LifetimeStatus: string
{
    case Healthy = 'healthy';
    case EndOfLife = 'eol'; // <4 hours remaining
    case Critical = 'critical'; // <1 hour remaining

    public static function worst(self $first, self $second): self
    {
        return $second->severity() > $first->severity() ? $second : $first;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 1,
            self::EndOfLife => 2,
            self::Critical => 3,
        };
    }
}
