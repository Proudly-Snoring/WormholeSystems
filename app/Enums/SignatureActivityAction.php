<?php

declare(strict_types=1);

namespace App\Enums;

enum SignatureActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Added',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
        };
    }
}
