<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\Type;

/**
 * Logs a tracked character's jump through a wormhole connection, with the
 * mass of the ship it used, and broadcasts the updated jump summary.
 */
final readonly class RecordMapConnectionJumpAction
{
    public function __construct(private BroadcastMapConnectionAction $broadcastMapConnection) {}

    public function handle(
        MapConnection $connection,
        int $character_id,
        int $from_solarsystem_id,
        int $to_solarsystem_id,
        ?int $ship_type_id,
        ?string $ship_name,
    ): MapConnectionJump {
        $jump = MapConnectionJump::query()->create([
            'map_id' => $connection->map_id,
            'map_connection_id' => $connection->id,
            'character_id' => $character_id,
            'from_solarsystem_id' => $from_solarsystem_id,
            'to_solarsystem_id' => $to_solarsystem_id,
            'ship_type_id' => $ship_type_id,
            'ship_name' => $ship_name,
            'mass' => (int) round(Type::query()->whereKey($ship_type_id)->value('mass') ?? 0),
        ]);

        $this->broadcastMapConnection->handle($connection);

        return $jump;
    }
}
