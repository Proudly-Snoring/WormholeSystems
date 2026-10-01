<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Actions\MapSolarsystem\LockMapSolarsystemsAction;
use App\Enums\ShipSize;
use App\Jobs\MapAlerts\EvaluateMapAlertsJob;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Wormhole;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateMapConnectionAction
{
    public function __construct(
        private MapBroadcaster $mapBroadcaster,
        private LockMapSolarsystemsAction $lockMapSolarsystemsAction,
    ) {}

    /**
     * Creates the connection, or returns the one already linking both systems
     * (check `wasRecentlyCreated` to tell them apart).
     *
     * @throws Throwable
     */
    public function handle(array $data): MapConnection
    {
        return DB::transaction(function () use ($data): MapConnection {
            /* Every creation path goes through here: locking both endpoints
             * before the re-check serializes concurrent creations between the
             * same systems (A→B by one pilot, B→A by another, or a manual add).
             */
            $this->lockMapSolarsystemsAction->handle($data['from_map_solarsystem_id'], $data['to_map_solarsystem_id']);

            /* A locking read, so it sees connections committed after this
             * transaction's snapshot was taken.
             */
            $existing_connection = MapConnection::query()
                ->connectsMapSolarsystems($data['from_map_solarsystem_id'], $data['to_map_solarsystem_id'])
                ->lockForUpdate()
                ->first();

            if ($existing_connection instanceof MapConnection) {
                return $existing_connection;
            }

            return $this->createConnection($data);
        });
    }

    private function createConnection(array $data): MapConnection
    {
        $map_id = MapSolarsystem::query()
            ->where('id', $data['from_map_solarsystem_id'])
            ->value('map_id');

        /* A known wormhole type dictates the ship size regardless of what the
         * caller passed; the hole's physics are not a matter of preference.
         */
        if (isset($data['wormhole_id'])) {
            $wormhole_ship_size = ShipSize::fromJumpMass(Wormhole::query()->whereKey($data['wormhole_id'])->value('maximum_jump_mass'));
            if ($wormhole_ship_size instanceof ShipSize) {
                $data['ship_size'] = $wormhole_ship_size;
            }
        }

        $map_connection = MapConnection::create([
            ...$data,
            'map_id' => $map_id,
        ]);

        /* A new wormhole edge can complete a route for alerts with a fixed starting
         * point even though no system was placed, so both endpoints re-evaluate.
         * Deliveries already sent for an endpoint are deduplicated by the ledger.
         */
        EvaluateMapAlertsJob::dispatch($map_connection->from_map_solarsystem_id)->afterCommit();
        EvaluateMapAlertsJob::dispatch($map_connection->to_map_solarsystem_id)->afterCommit();

        $this->mapBroadcaster->connectionsUpserted($map_id, MapConnection::query()
            ->whereKey($map_connection->id)
            ->with('signatures.signatureType', 'signatures.wormhole')
            ->withJumpSummary()
            ->get());

        return $map_connection;
    }
}
