<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Actions\ShipHistories\UpdateShipHistoryAction;
use App\Actions\Tracking\TrackCharacterJumpAction;
use App\Models\CharacterStatus;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NicolasKion\Esi\DTO\Location;
use NicolasKion\Esi\DTO\Ship;
use NicolasKion\Esi\Esi;
use Throwable;

final class UpdateCharacterLocation implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $character_status_id)
    {
        //
    }

    /**
     * Execute the job.
     *
     * @throws Throwable
     * @throws ConnectionException
     */
    public function handle(Esi $esi, UpdateShipHistoryAction $action, TrackCharacterJumpAction $trackJumpAction): void
    {
        $characterStatus = CharacterStatus::query()->find($this->character_status_id);

        if ($characterStatus === null) {
            return;
        }

        $location_request = $esi->getLocation($characterStatus->character);

        if ($location_request->failed()) {
            Log::info(sprintf('Failed to fetch location for character %d', $characterStatus->character_id), (array) $location_request);

            return;
        }

        $ship_request = $esi->getShip($characterStatus->character);

        if ($ship_request->failed()) {
            Log::info(sprintf('Failed to fetch ship for character %d', $characterStatus->character_id), (array) $ship_request);

            return;
        }

        $location = $location_request->data;
        $ship = $ship_request->data;

        $previous_solarsystem_id = $characterStatus->solarsystem_id;
        $was_docked = $characterStatus->station_id !== null || $characterStatus->structure_id !== null;
        $is_continuous = $this->observeContinuity($characterStatus);

        $characterStatus->update([
            'solarsystem_id' => $location->solar_system_id,
            'station_id' => $location->station_id,
            'structure_id' => $location->structure_id,
            'ship_name' => $ship->ship_name,
            'ship_type_id' => $ship->ship_type_id,
            'ship_item_id' => $ship->ship_item_id,
        ]);

        $action->handle(
            $characterStatus->character_id,
            $ship->ship_item_id,
            $ship->ship_type_id,
            $ship->ship_name
        );

        if ($characterStatus->wasChanged()) {
            // Mark that event should be dispatched
            $characterStatus->update(['event_queued_at' => now()]);
        }

        /* Only a move from a position observed by a recent poll is a jump.
         * Otherwise it is a relocation (login elsewhere, site closed for a
         * while, ESI outage...): the new system is only the starting point.
         */
        if (! $is_continuous || $previous_solarsystem_id === null) {
            return;
        }

        $this->trackJump(
            $trackJumpAction,
            $characterStatus,
            $previous_solarsystem_id,
            $location,
            $ship,
            $was_docked || $location->station_id !== null || $location->structure_id !== null,
        );
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping((string) $this->character_status_id)->dontRelease()->expireAfter(60)];
    }

    /**
     * Whether the previous successful poll of this character is recent enough
     * for a change of system to be a jump. Refreshes the marker for the next poll.
     */
    private function observeContinuity(CharacterStatus $characterStatus): bool
    {
        $store = Cache::store(config('map.tracking.continuity_store'));
        $key = sprintf('character_location_checked.%d', $characterStatus->id);

        $is_continuous = $store->has($key);
        $store->put($key, true, config('map.tracking.continuity_seconds'));

        return $is_continuous;
    }

    /**
     * A jump-tracking failure must never break location polling.
     */
    private function trackJump(
        TrackCharacterJumpAction $trackJumpAction,
        CharacterStatus $characterStatus,
        int $previous_solarsystem_id,
        Location $location,
        Ship $ship,
        bool $docked,
    ): void {
        try {
            $trackJumpAction->handle(
                $characterStatus->character,
                $previous_solarsystem_id,
                $location->solar_system_id,
                $ship->ship_type_id,
                $ship->ship_name,
                $docked,
            );
        } catch (Throwable $exception) {
            Log::warning(sprintf('Failed to track jump for character %d', $characterStatus->character_id), [
                'exception' => $exception,
            ]);
        }
    }
}
