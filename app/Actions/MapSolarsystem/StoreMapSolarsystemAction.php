<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Actions\MapConnections\CreateMapConnectionAction;
use App\Jobs\MapAlerts\EvaluateMapAlertsJob;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use Throwable;

final readonly class StoreMapSolarsystemAction
{
    public function __construct(
        private CreateMapConnectionAction $createMapConnection,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(Map $map, array $data): MapSolarsystem
    {
        // Persistent intel is kept (or created with defaults) so it survives a system being
        // removed and re-added; only the placement is (re)created.
        $details = $map->mapSolarsystemDetails()->firstOrCreate([
            'solarsystem_id' => $data['solarsystem_id'],
        ]);

        $map_solarsystem = $map->mapSolarsystems()->firstOrNew([
            'solarsystem_id' => $data['solarsystem_id'],
        ]);
        $map_solarsystem->map_solarsystem_details_id = $details->id;

        // Only a newly placed system takes the requested position; re-adding one already on
        // the map (e.g. to connect to it) must leave it where the user put it.
        if (! $map_solarsystem->exists) {
            $map_solarsystem->position_x = $data['position_x'];
            $map_solarsystem->position_y = $data['position_y'];
        }
        $map_solarsystem->save();

        EvaluateMapAlertsJob::dispatch($map_solarsystem->id)->afterCommit();

        $this->connectToOrigin($map_solarsystem, $data['connect_to_map_solarsystem_id'] ?? null);

        $this->mapBroadcaster->systemsUpserted($map->id, MapSolarsystem::query()
            ->whereKey($map_solarsystem->id)
            ->with('details')
            ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
            ->get());

        return $map_solarsystem;
    }

    /**
     * Link the freshly placed system back to the system it was added from (when the user
     * used "Add connection"), skipping self-links. An existing connection is kept as is
     * by CreateMapConnectionAction.
     *
     * @throws Throwable
     */
    private function connectToOrigin(MapSolarsystem $target, ?int $origin_id): void
    {
        if ($origin_id === null || $origin_id === $target->id) {
            return;
        }

        $this->createMapConnection->handle([
            'from_map_solarsystem_id' => $origin_id,
            'to_map_solarsystem_id' => $target->id,
        ]);
    }
}
