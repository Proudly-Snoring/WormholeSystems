<?php

declare(strict_types=1);

use App\Actions\Tracking\TrackCharacterJumpAction;
use App\Enums\ConnectionType;
use App\Enums\SolarsystemClass;
use App\Events\Characters\CharacterJumpedEvent;
use App\Events\MapConnections\MapConnectionsUpsertedEvent;
use App\Models\Category;
use App\Models\Character;
use App\Models\Group;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapIgnoredSolarsystem;
use App\Models\MapUserSetting;
use App\Models\Type;
use App\Models\WormholeSystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

const JUMP_SHIP_TYPE_ID = 73790;

const JUMP_SHIP_MASS = 130_000_000;

const CAPSULE_TYPE_ID = 670;

/**
 * Reloaded so the id is the database integer, not the factory's float.
 */
function createTrackedCharacter(?int $user_id = null): Character
{
    $attributes = $user_id === null ? [] : ['user_id' => $user_id];

    return Character::factory()->create($attributes)->fresh();
}

function createTrackedMap(Character $character, bool $tracking_allowed = true, bool $is_tracking = true): Map
{
    $map = Map::factory()->create();

    MapUserSetting::query()->create([
        'map_id' => $map->id,
        'user_id' => $character->user_id,
        'tracking_allowed' => $tracking_allowed,
        'is_tracking' => $is_tracking,
    ]);

    return $map;
}

function createJumpShipType(int $type_id = JUMP_SHIP_TYPE_ID, float $mass = JUMP_SHIP_MASS, int $group_id = 27, string $group_name = 'Battleship'): Type
{
    Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    Group::query()->firstOrCreate(['id' => $group_id], ['name' => $group_name, 'category_id' => 6]);

    return Type::query()->firstOrCreate(
        ['id' => $type_id],
        ['name' => 'Ship '.$type_id, 'group_id' => $group_id, 'mass' => $mass],
    );
}

function createCapsuleType(): Type
{
    return createJumpShipType(CAPSULE_TYPE_ID, 32_000, 29, 'Capsule');
}

function makeWormholeSolarsystem(int $solarsystem_id, SolarsystemClass $class): int
{
    makeSolarsystem($solarsystem_id, -1.0, 'wormhole');
    WormholeSystem::query()->create(['id' => $solarsystem_id, 'class' => $class]);

    return $solarsystem_id;
}

/**
 * @return array{map: Map, connection: MapConnection, from: int, to: int}
 */
function createTrackedMapWithConnection(Character $character, int $from_solarsystem_id, int $to_solarsystem_id): array
{
    $map = createTrackedMap($character);
    $origin = placeMapSolarsystem($map, $from_solarsystem_id);
    $target = placeMapSolarsystem($map, $to_solarsystem_id, 300, 300);

    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
    ]);

    return ['map' => $map, 'connection' => $connection, 'from' => $from_solarsystem_id, 'to' => $to_solarsystem_id];
}

function linkSolarsystemsByStargate(int $from_solarsystem_id, int $to_solarsystem_id): void
{
    Group::query()->firstOrCreate(['id' => 10], ['name' => 'Stargate', 'category_id' => 6]);
    Type::query()->firstOrCreate(['id' => 16], ['name' => 'Stargate', 'group_id' => 10]);

    $stargates = [
        ['id' => $from_solarsystem_id * 100, 'solarsystem_id' => $from_solarsystem_id],
        ['id' => $to_solarsystem_id * 100, 'solarsystem_id' => $to_solarsystem_id],
    ];

    foreach ($stargates as $stargate) {
        DB::table('stargates')->insertOrIgnore([
            'id' => $stargate['id'],
            'solarsystem_id' => $stargate['solarsystem_id'],
            'constellation_id' => 20009000,
            'region_id' => 10009000,
            'type_id' => 16,
        ]);
    }

    DB::table('solarsystem_connections')->insertOrIgnore([
        'from_stargate_id' => $from_solarsystem_id * 100,
        'from_solarsystem_id' => $from_solarsystem_id,
        'from_constellation_id' => 20009000,
        'from_region_id' => 10009000,
        'to_stargate_id' => $to_solarsystem_id * 100,
        'to_solarsystem_id' => $to_solarsystem_id,
        'to_constellation_id' => 20009000,
        'to_region_id' => 10009000,
    ]);
}

function recordJump(Character $character, int $from_solarsystem_id, int $to_solarsystem_id, int $ship_type_id = JUMP_SHIP_TYPE_ID, bool $docked = false): void
{
    app(TrackCharacterJumpAction::class)->handle(
        $character,
        $from_solarsystem_id,
        $to_solarsystem_id,
        $ship_type_id,
        'Test Ship',
        $docked,
    );
}

it('records a jump through an existing wormhole connection with the ship mass snapshot', function () {
    Event::fake([MapConnectionsUpsertedEvent::class]);

    $character = createTrackedCharacter();
    createJumpShipType();
    ['connection' => $connection] = createTrackedMapWithConnection($character, 31000101, 31000102);

    recordJump($character, 31000101, 31000102);

    $jump = MapConnectionJump::query()->sole();
    expect($jump->map_connection_id)->toBe($connection->id)
        ->and($jump->character_id)->toBe((int) $character->id)
        ->and($jump->ship_type_id)->toBe(JUMP_SHIP_TYPE_ID)
        ->and($jump->mass)->toBe(JUMP_SHIP_MASS)
        ->and(MapConnection::query()->count())->toBe(1);

    Event::assertDispatched(MapConnectionsUpsertedEvent::class, function (MapConnectionsUpsertedEvent $event) use ($connection): bool {
        return collect($event->broadcastWith()['map_connections'])
            ->contains(fn (array $payload): bool => $payload['id'] === $connection->id
                && $payload['jumps_mass_sum'] === JUMP_SHIP_MASS
                && $payload['jumps_count'] === 1);
    });
});

it('records a jump in the reverse direction of the connection', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    ['connection' => $connection] = createTrackedMapWithConnection($character, 31000103, 31000104);

    recordJump($character, 31000104, 31000103);

    expect(MapConnectionJump::query()->sole()->map_connection_id)->toBe($connection->id)
        ->and(MapConnection::query()->count())->toBe(1);
});

it('skips stargate-type connections', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    ['connection' => $connection] = createTrackedMapWithConnection($character, 31000105, 31000106);
    $connection->update(['type' => ConnectionType::Stargate]);

    recordJump($character, 31000105, 31000106);

    expect(MapConnectionJump::query()->count())->toBe(0);
});

it('creates the connection and logs the jump on it when it does not exist yet', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    $map = createTrackedMap($character);
    placeMapSolarsystem($map, 31000107);
    makeSolarsystem(31000108);

    recordJump($character, 31000107, 31000108);

    $connection = MapConnection::query()->where('map_id', $map->id)->sole();
    $jump = MapConnectionJump::query()->sole();
    expect($jump->map_connection_id)->toBe($connection->id)
        ->and($jump->mass)->toBe(JUMP_SHIP_MASS)
        ->and($map->mapSolarsystems()->where('solarsystem_id', 31000108)->exists())->toBeTrue();
});

it('broadcasts the jump with its map and connection to the owner of the character', function () {
    Event::fake([CharacterJumpedEvent::class]);

    $character = createTrackedCharacter();
    createJumpShipType();
    ['map' => $map, 'connection' => $connection] = createTrackedMapWithConnection($character, 31000124, 31000125);

    recordJump($character, 31000124, 31000125);

    Event::assertDispatched(CharacterJumpedEvent::class, function (CharacterJumpedEvent $event) use ($character, $map, $connection): bool {
        return $event->broadcastOn()[0]->name === sprintf('private-User.%d', $character->user_id)
            && $event->broadcastWith() === [
                'map_id' => $map->id,
                'character_id' => (int) $character->id,
                'from_solarsystem_id' => 31000124,
                'to_solarsystem_id' => 31000125,
                'ship_type_id' => JUMP_SHIP_TYPE_ID,
                'map_connection_id' => $connection->id,
            ];
    });
});

it('tracks every character of the user, not only the active one', function () {
    $character = createTrackedCharacter();
    $alt = createTrackedCharacter($character->user_id);
    createJumpShipType();
    ['connection' => $connection] = createTrackedMapWithConnection($character, 31000126, 31000127);

    recordJump($alt, 31000126, 31000127);

    expect(MapConnectionJump::query()->sole())
        ->map_connection_id->toBe($connection->id)
        ->character_id->toBe((int) $alt->id);
});

it('stores nothing when the origin system is not on the map', function () {
    Event::fake([CharacterJumpedEvent::class]);

    $character = createTrackedCharacter();
    createJumpShipType();
    createTrackedMap($character);
    makeSolarsystem(31000109);
    makeSolarsystem(31000110);

    recordJump($character, 31000109, 31000110);

    expect(MapConnectionJump::query()->count())->toBe(0)
        ->and(MapConnection::query()->count())->toBe(0);
    Event::assertNotDispatched(CharacterJumpedEvent::class);
});

it('stores nothing when the target system is ignored by the map', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    $map = createTrackedMap($character);
    placeMapSolarsystem($map, 31000128);
    makeSolarsystem(31000129);
    MapIgnoredSolarsystem::query()->create(['map_id' => $map->id, 'solarsystem_id' => 31000129]);

    recordJump($character, 31000128, 31000129);

    expect(MapConnectionJump::query()->count())->toBe(0)
        ->and(MapConnection::query()->count())->toBe(0);
});

it('stores nothing for k-space systems linked by stargates', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    $map = createTrackedMap($character);
    makeSolarsystem(31000111, type: 'eve');
    makeSolarsystem(31000112, type: 'eve');
    placeMapSolarsystem($map, 31000111);
    linkSolarsystemsByStargate(31000111, 31000112);

    recordJump($character, 31000111, 31000112);

    expect(MapConnectionJump::query()->count())->toBe(0)
        ->and(MapConnection::query()->count())->toBe(0);
});

it('fans out to every map tracking the character but skips maps without tracking consent', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    ['connection' => $first_connection] = createTrackedMapWithConnection($character, 31000113, 31000114);

    $second_map = createTrackedMap($character);
    $second_origin = placeMapSolarsystem($second_map, 31000113);
    $second_target = placeMapSolarsystem($second_map, 31000114, 300, 300);
    $second_connection = MapConnection::factory()->create([
        'map_id' => $second_map->id,
        'from_map_solarsystem_id' => $second_origin->id,
        'to_map_solarsystem_id' => $second_target->id,
    ]);

    $untracked_map = createTrackedMap($character, is_tracking: false);
    $untracked_origin = placeMapSolarsystem($untracked_map, 31000113);
    $untracked_target = placeMapSolarsystem($untracked_map, 31000114, 300, 300);
    MapConnection::factory()->create([
        'map_id' => $untracked_map->id,
        'from_map_solarsystem_id' => $untracked_origin->id,
        'to_map_solarsystem_id' => $untracked_target->id,
    ]);

    recordJump($character, 31000113, 31000114);

    expect(MapConnectionJump::query()->pluck('map_connection_id')->sort()->values()->all())
        ->toBe(collect([$first_connection->id, $second_connection->id])->sort()->values()->all());
});

it('keeps tracking the other maps when one map fails', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    $failing_map = createTrackedMap($character);
    placeMapSolarsystem($failing_map, 31000130);
    $working_map = createTrackedMap($character);
    placeMapSolarsystem($working_map, 31000130);
    makeSolarsystem(31000131);

    MapConnection::creating(function (MapConnection $connection) use ($failing_map): void {
        throw_if($connection->map_id === $failing_map->id, RuntimeException::class, 'Lock wait timeout');
    });

    recordJump($character, 31000130, 31000131);

    expect(MapConnection::query()->sole()->map_id)->toBe($working_map->id)
        ->and(MapConnectionJump::query()->sole()->map_id)->toBe($working_map->id);
});

it('checks that the ship fits through a wormhole into or out of a C1 or C13', function (SolarsystemClass $from_class, SolarsystemClass $to_class, float $ship_mass, bool $tracked) {
    $character = createTrackedCharacter();
    createJumpShipType(mass: $ship_mass);
    $map = createTrackedMap($character);
    placeMapSolarsystem($map, makeWormholeSolarsystem(31000132, $from_class));
    makeWormholeSolarsystem(31000133, $to_class);

    recordJump($character, 31000132, 31000133);

    expect(MapConnection::query()->count())->toBe($tracked ? 1 : 0)
        ->and(MapConnectionJump::query()->count())->toBe($tracked ? 1 : 0);
})->with([
    'battleship into a C1' => [SolarsystemClass::C5, SolarsystemClass::C1, 100_000_000, false],
    'battleship out of a C1' => [SolarsystemClass::C1, SolarsystemClass::C5, 100_000_000, false],
    'cruiser into a C1' => [SolarsystemClass::C5, SolarsystemClass::C1, 12_000_000, true],
    'destroyer into a C13' => [SolarsystemClass::C5, SolarsystemClass::C13, 1_800_000, true],
    'cruiser into a C13' => [SolarsystemClass::C5, SolarsystemClass::C13, 12_000_000, false],
    'cruiser out of a C13 into a C1' => [SolarsystemClass::C13, SolarsystemClass::C1, 12_000_000, false],
    'battleship between unrestricted classes' => [SolarsystemClass::C5, SolarsystemClass::C6, 100_000_000, true],
]);

it('lets a battleship through Thera or Turnur', function (string $name) {
    $character = createTrackedCharacter();
    createJumpShipType(mass: 100_000_000);
    $map = createTrackedMap($character);
    placeMapSolarsystem($map, makeSolarsystem(31000134, 0.9, 'eve'));
    makeSolarsystem(31000135, -1.0, 'wormhole');
    DB::table('solarsystems')->where('id', 31000135)->update(['name' => $name]);

    recordJump($character, 31000134, 31000135);

    expect(MapConnectionJump::query()->count())->toBe(1);
})->with(['Thera', 'Turnur']);

it('tracks a jump when the ship mass is unknown', function () {
    $character = createTrackedCharacter();
    $map = createTrackedMap($character);
    placeMapSolarsystem($map, makeWormholeSolarsystem(31000136, SolarsystemClass::C5));
    makeWormholeSolarsystem(31000137, SolarsystemClass::C1);

    recordJump($character, 31000136, 31000137, ship_type_id: 999_999);

    expect(MapConnection::query()->count())->toBe(1);
});

it('rejects a capsule leaving or reaching a station or structure', function (bool $capsule, bool $docked, bool $tracked) {
    $character = createTrackedCharacter();
    $ship = $capsule ? createCapsuleType() : createJumpShipType();
    $map = createTrackedMap($character);
    placeMapSolarsystem($map, 31000138);
    makeSolarsystem(31000139);

    recordJump($character, 31000138, 31000139, ship_type_id: $ship->id, docked: $docked);

    expect(MapConnection::query()->count())->toBe($tracked ? 1 : 0)
        ->and(MapConnectionJump::query()->count())->toBe($tracked ? 1 : 0);
})->with([
    'capsule docked on one side' => [true, true, false],
    'capsule in space to space' => [true, false, true],
    'ship arriving docked' => [false, true, true],
]);

it('deletes the jump log together with its connection', function () {
    $character = createTrackedCharacter();
    createJumpShipType();
    ['connection' => $connection] = createTrackedMapWithConnection($character, 31000122, 31000123);

    recordJump($character, 31000122, 31000123);
    expect(MapConnectionJump::query()->count())->toBe(1);

    $connection->delete();

    expect(MapConnectionJump::query()->count())->toBe(0);
});
