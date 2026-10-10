<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\MapSolarsystem;

/**
 * Locks map solarsystem rows for the rest of the transaction, always in
 * ascending id order so two transactions locking the same pair (A→B and
 * B→A) queue up instead of deadlocking.
 */
final readonly class LockMapSolarsystemsAction
{
    public function handle(?int ...$map_solarsystem_ids): void
    {
        $ids = collect($map_solarsystem_ids)->filter()->unique()->sort();

        foreach ($ids as $id) {
            MapSolarsystem::query()->whereKey($id)->lockForUpdate()->first();
        }
    }
}
