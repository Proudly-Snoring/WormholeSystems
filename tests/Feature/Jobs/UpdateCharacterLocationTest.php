<?php

declare(strict_types=1);

use App\Events\Characters\CharacterJumpedEvent;
use App\Jobs\Characters\UpdateCharacterLocation;
use App\Models\Category;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\Group;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapUserSetting;
use App\Models\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use NicolasKion\Esi\DTO\EsiError;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\Location;
use NicolasKion\Esi\DTO\Ship;
use NicolasKion\Esi\Esi;

const LOCATION_SHIP_TYPE_ID = 73791;

const LOCATION_SHIP_MASS = 130_000_000;

/**
 * @return array{character: Character, status: CharacterStatus, connection: MapConnection}
 */
function createTrackedCharacterOnConnection(int $from_solarsystem_id, int $to_solarsystem_id): array
{
    $character = Character::factory()->create();

    $map = Map::factory()->create();
    MapUserSetting::query()->create([
        'map_id' => $map->id,
        'user_id' => $character->user_id,
        'tracking_allowed' => true,
        'is_tracking' => true,
    ]);

    $origin = placeMapSolarsystem($map, $from_solarsystem_id);
    $target = placeMapSolarsystem($map, $to_solarsystem_id, 300, 300);

    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
    ]);

    Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    Group::query()->firstOrCreate(['id' => 27], ['name' => 'Battleship', 'category_id' => 6]);
    Type::query()->firstOrCreate(
        ['id' => LOCATION_SHIP_TYPE_ID],
        ['name' => 'Nestor', 'group_id' => 27, 'mass' => LOCATION_SHIP_MASS],
    );

    $status = CharacterStatus::query()->create([
        'character_id' => $character->id,
        'solarsystem_id' => $from_solarsystem_id,
        'is_online' => true,
    ]);

    return ['character' => $character, 'status' => $status, 'connection' => $connection];
}

/**
 * Mark the character's previous position as observed by a recent poll.
 */
function markLocationObserved(CharacterStatus $status): void
{
    Cache::store('array')->put(sprintf('character_location_checked.%d', $status->id), true, 45);
}

function fakeEsiLocation(?Location $location, ?Ship $ship = null): void
{
    $esi = test()->mock(Esi::class);

    if (! $location instanceof Location) {
        $esi->shouldReceive('getLocation')->andReturn(new EsiResult(error: new EsiError(500, 'unavailable')));

        return;
    }

    $esi->shouldReceive('getLocation')->andReturn(new EsiResult(data: $location));
    $esi->shouldReceive('getShip')->andReturn(new EsiResult(
        data: $ship ?? new Ship(ship_type_id: LOCATION_SHIP_TYPE_ID, ship_item_id: 9001, ship_name: 'Blackbetty'),
    ));
}

it('records a connection jump when a tracked character moves between connected systems', function () {
    ['status' => $status, 'connection' => $connection] = createTrackedCharacterOnConnection(31000201, 31000202);
    markLocationObserved($status);

    fakeEsiLocation(new Location(solar_system_id: 31000202));

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->solarsystem_id)->toBe(31000202);

    $jump = MapConnectionJump::query()->sole();
    expect($jump->map_connection_id)->toBe($connection->id)
        ->and($jump->ship_type_id)->toBe(LOCATION_SHIP_TYPE_ID)
        ->and($jump->ship_name)->toBe('Blackbetty')
        ->and($jump->mass)->toBe(LOCATION_SHIP_MASS);
});

it('creates the connection when a tracked character jumps into a system not yet connected', function () {
    Event::fake([CharacterJumpedEvent::class]);

    ['status' => $status, 'connection' => $connection] = createTrackedCharacterOnConnection(31000207, 31000208);
    makeSolarsystem(31000209);
    markLocationObserved($status);

    fakeEsiLocation(new Location(solar_system_id: 31000209));

    UpdateCharacterLocation::dispatchSync($status->id);

    $new_connection = MapConnection::query()->whereKeyNot($connection->id)->sole();
    expect(MapConnectionJump::query()->sole()->map_connection_id)->toBe($new_connection->id);
    Event::assertDispatched(CharacterJumpedEvent::class, fn (CharacterJumpedEvent $event): bool => $event->map_connection_id === $new_connection->id);
});

it('treats a move as a relocation when the previous position was not observed recently', function () {
    Event::fake([CharacterJumpedEvent::class]);

    ['status' => $status] = createTrackedCharacterOnConnection(31000210, 31000211);
    makeSolarsystem(31000212);

    fakeEsiLocation(new Location(solar_system_id: 31000212));

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->solarsystem_id)->toBe(31000212)
        ->and(MapConnection::query()->count())->toBe(1)
        ->and(MapConnectionJump::query()->count())->toBe(0);
    Event::assertNotDispatched(CharacterJumpedEvent::class);
});

it('tracks the next move once a first poll has observed the position', function () {
    ['status' => $status, 'connection' => $connection] = createTrackedCharacterOnConnection(31000213, 31000214);

    fakeEsiLocation(new Location(solar_system_id: 31000213));
    UpdateCharacterLocation::dispatchSync($status->id);

    fakeEsiLocation(new Location(solar_system_id: 31000214));
    UpdateCharacterLocation::dispatchSync($status->id);

    expect(MapConnectionJump::query()->sole()->map_connection_id)->toBe($connection->id);
});

it('treats a move as a relocation once the continuity window has passed', function () {
    ['status' => $status] = createTrackedCharacterOnConnection(31000215, 31000216);

    fakeEsiLocation(new Location(solar_system_id: 31000215));
    UpdateCharacterLocation::dispatchSync($status->id);

    $this->travel(46)->seconds();

    fakeEsiLocation(new Location(solar_system_id: 31000216));
    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->solarsystem_id)->toBe(31000216)
        ->and(MapConnectionJump::query()->count())->toBe(0);
});

it('does not refresh continuity when the location request fails', function () {
    ['status' => $status] = createTrackedCharacterOnConnection(31000217, 31000218);

    fakeEsiLocation(null);
    UpdateCharacterLocation::dispatchSync($status->id);

    fakeEsiLocation(new Location(solar_system_id: 31000218));
    UpdateCharacterLocation::dispatchSync($status->id);

    expect(MapConnectionJump::query()->count())->toBe(0);
});

it('rejects a capsule jump out of a structure', function () {
    ['status' => $status] = createTrackedCharacterOnConnection(31000219, 31000220);
    $status->update(['structure_id' => 1035000000000]);
    markLocationObserved($status);

    Group::query()->firstOrCreate(['id' => 29], ['name' => 'Capsule', 'category_id' => 6]);
    Type::query()->firstOrCreate(['id' => 670], ['name' => 'Capsule', 'group_id' => 29, 'mass' => 32_000]);

    fakeEsiLocation(
        new Location(solar_system_id: 31000220),
        new Ship(ship_type_id: 670, ship_item_id: 9002, ship_name: 'Pod'),
    );

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->solarsystem_id)->toBe(31000220)
        ->and(MapConnectionJump::query()->count())->toBe(0);
});

it('does not record a jump when the character stays in the same system', function () {
    ['status' => $status] = createTrackedCharacterOnConnection(31000203, 31000204);
    markLocationObserved($status);

    fakeEsiLocation(new Location(solar_system_id: 31000203, structure_id: 1035000000000));

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->structure_id)->toBe(1035000000000)
        ->and(MapConnectionJump::query()->count())->toBe(0);
});

it('records nothing when the location request fails', function () {
    ['status' => $status] = createTrackedCharacterOnConnection(31000205, 31000206);

    fakeEsiLocation(null);

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->solarsystem_id)->toBe(31000205)
        ->and(MapConnectionJump::query()->count())->toBe(0);
});
