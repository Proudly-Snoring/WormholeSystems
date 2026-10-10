<?php

declare(strict_types=1);

namespace App\Events\Characters;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A tracked character jumped through a wormhole connection of a map. Sent to
 * the character's owner only, whose tabs follow the pilot and offer to link a
 * signature to the connection.
 */
final class CharacterJumpedEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $user_id,
        public int $map_id,
        public int $character_id,
        public int $from_solarsystem_id,
        public int $to_solarsystem_id,
        public ?int $ship_type_id,
        public int $map_connection_id,
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('User.%d', $this->user_id)),
        ];
    }

    /**
     * @return array{map_id: int, character_id: int, from_solarsystem_id: int, to_solarsystem_id: int, ship_type_id: int|null, map_connection_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'map_id' => $this->map_id,
            'character_id' => $this->character_id,
            'from_solarsystem_id' => $this->from_solarsystem_id,
            'to_solarsystem_id' => $this->to_solarsystem_id,
            'ship_type_id' => $this->ship_type_id,
            'map_connection_id' => $this->map_connection_id,
        ];
    }
}
