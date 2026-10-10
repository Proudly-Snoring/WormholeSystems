<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Actions\MapConnections\RecordMapConnectionJumpAction;
use App\Data\TrackingData;
use App\Enums\ConnectionType;
use App\Enums\SolarsystemClass;
use App\Events\Characters\CharacterJumpedEvent;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Solarsystem;
use App\Models\Type;
use App\Utilities\StargatePairDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a continuous change of system of a tracked character into a wormhole
 * connection and a mass entry, on every map tracking the character.
 */
final readonly class TrackCharacterJumpAction
{
    private const int CAPSULE_GROUP_ID = 29;

    /**
     * Maximum jump mass of any wormhole leading to or from a C13 system.
     */
    private const float C13_MAXIMUM_JUMP_MASS = 5_000_000;

    /**
     * Maximum jump mass of any wormhole leading to or from a C1 system.
     */
    private const float C1_MAXIMUM_JUMP_MASS = 62_000_000;

    public function __construct(
        private StargatePairDetector $stargatePairDetector,
        private StoreTrackingAction $storeTrackingAction,
        private RecordMapConnectionJumpAction $recordMapConnectionJumpAction,
    ) {}

    /**
     * @param  bool  $docked  whether the character was docked before or after the move
     */
    public function handle(
        Character $character,
        int $from_solarsystem_id,
        int $to_solarsystem_id,
        ?int $ship_type_id,
        ?string $ship_name,
        bool $docked,
    ): void {
        if ($character->user_id === null || $from_solarsystem_id === $to_solarsystem_id) {
            return;
        }

        $from_solarsystem = Solarsystem::query()->with('wormholeSystem')->find($from_solarsystem_id);
        $to_solarsystem = Solarsystem::query()->with('wormholeSystem')->find($to_solarsystem_id);

        if ($from_solarsystem === null || $to_solarsystem === null) {
            return;
        }

        /* A gate pair means the character travelled through a stargate, not a
         * wormhole: nothing is created nor charged.
         */
        if ($this->stargatePairDetector->isStargatePair($from_solarsystem, $to_solarsystem)) {
            return;
        }

        $ship = Type::query()->find($ship_type_id);

        if (! $this->shipFits($ship, $from_solarsystem, $to_solarsystem)) {
            return;
        }

        /* A capsule leaving or reaching a station or structure is a pod death
         * (back to the medical clone) or a clone jump, not a wormhole transit.
         */
        if ($docked && $ship?->group_id === self::CAPSULE_GROUP_ID) {
            return;
        }

        foreach ($this->getMapsTrackingCharacter($character->id) as $map) {
            /* A failure on one map (lock timeout, deadlock) never stops the
             * other maps nor the location polling.
             */
            try {
                $this->trackJumpOnMap($map, $character, $from_solarsystem_id, $to_solarsystem_id, $ship_type_id, $ship_name);
            } catch (Throwable $exception) {
                Log::warning(sprintf('Failed to track jump of character %d on map %d', $character->id, $map->id), [
                    'exception' => $exception,
                ]);
            }
        }
    }

    /**
     * @throws Throwable
     */
    private function trackJumpOnMap(
        Map $map,
        Character $character,
        int $from_solarsystem_id,
        int $to_solarsystem_id,
        ?int $ship_type_id,
        ?string $ship_name,
    ): void {
        if ($map->mapIgnoredSolarsystems()->where('solarsystem_id', $to_solarsystem_id)->exists()) {
            return;
        }

        $origin = $map->mapSolarsystems()
            ->where('solarsystem_id', $from_solarsystem_id)
            ->whereNotNull('position_x')
            ->first();

        if (! $origin instanceof MapSolarsystem) {
            return;
        }

        $existing_connection = MapConnection::query()
            ->connectsSolarsystemsInMap($map->id, $from_solarsystem_id, $to_solarsystem_id)
            ->first();

        if ($existing_connection instanceof MapConnection && $existing_connection->type !== ConnectionType::Wormhole) {
            return;
        }

        $connection = $this->storeTrackingAction->handle(new TrackingData(
            from_map_solarsystem_id: $origin->id,
            to_solarsystem_id: $to_solarsystem_id,
        ));

        if (! $connection instanceof MapConnection) {
            return;
        }

        $this->recordMapConnectionJumpAction->handle(
            $connection,
            $character->id,
            $from_solarsystem_id,
            $to_solarsystem_id,
            $ship_type_id,
            $ship_name,
        );

        CharacterJumpedEvent::dispatch(
            $character->user_id,
            $map->id,
            $character->id,
            $from_solarsystem_id,
            $to_solarsystem_id,
            $ship_type_id,
            $connection->id,
        );
    }

    /**
     * Wormhole mass limits apply both ways: a ship too heavy for a hole into
     * a C1 or C13 can neither enter nor leave it. The hull mass is a lower
     * bound of the real mass, so a rejected move cannot have been a wormhole.
     */
    private function shipFits(?Type $ship, Solarsystem $from, Solarsystem $to): bool
    {
        $classes = [$from->wormholeSystem?->class, $to->wormholeSystem?->class];

        $maximum_jump_mass = match (true) {
            in_array(SolarsystemClass::C13, $classes, true) => self::C13_MAXIMUM_JUMP_MASS,
            in_array(SolarsystemClass::C1, $classes, true) => self::C1_MAXIMUM_JUMP_MASS,
            default => null,
        };

        if ($maximum_jump_mass === null || $ship?->mass === null) {
            return true;
        }

        return $ship->mass <= $maximum_jump_mass;
    }

    /**
     * @return Collection<int, Map>
     */
    private function getMapsTrackingCharacter(int $character_id): Collection
    {
        return Map::query()
            ->whereHas('mapUserSettings', fn (Builder $query) => $query
                ->where('tracking_allowed', true)
                ->where('is_tracking', true)
                ->whereHas('user.characters', fn (Builder $query) => $query->where('id', $character_id))
            )
            ->get();
    }
}
