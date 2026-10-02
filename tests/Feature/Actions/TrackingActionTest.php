<?php

declare(strict_types=1);

use App\Actions\MapConnections\CreateMapConnectionAction;
use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Actions\Tracking\StoreTrackingAction;
use App\Data\TrackingData;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\ShipSize;
use App\Events\MapSolarsystems\MapSolarsystemsUpsertedEvent;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\Signature;
use Illuminate\Support\Facades\Event;

it('adds the target system and connects it when tracking a jump', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012001);
    $targetId = makeSolarsystem(30012002);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
    ]));

    expect(MapConnection::where('map_id', $map->id)->count())->toBe(1)
        ->and($map->mapSolarsystems()->where('solarsystem_id', $targetId)->whereNotNull('position_x')->exists())->toBeTrue();
});

it('does not duplicate a connection that already exists', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012003);
    $targetId = makeSolarsystem(30012004);

    $data = TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
    ]);

    app(StoreTrackingAction::class)->handle($data);
    app(StoreTrackingAction::class)->handle($data);

    expect(MapConnection::where('map_id', $map->id)->count())->toBe(1);
});

it('assigns the alias to a tracked system and broadcasts it', function () {
    Event::fake([MapSolarsystemsUpsertedEvent::class]);

    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012005);
    $targetId = makeSolarsystem(30012006);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
        'alias' => 'C2a',
    ]));

    $target = $map->mapSolarsystems()->where('solarsystem_id', $targetId)->firstOrFail();
    expect($target->alias)->toBe('C2a');

    Event::assertDispatched(MapSolarsystemsUpsertedEvent::class, function (MapSolarsystemsUpsertedEvent $event) use ($target): bool {
        return collect($event->broadcastWith()['map_solarsystems'])
            ->contains(fn (array $system): bool => $system['id'] === $target->id && $system['alias'] === 'C2a');
    });
});

it('assigns the alias when the tracked system is already on the map', function () {
    Event::fake([MapSolarsystemsUpsertedEvent::class]);

    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012007);
    $target = placeMapSolarsystem($map, 30012008, 300, 300);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $target->solarsystem_id,
        'alias' => 'STATIC',
    ]));

    expect($target->fresh()->alias)->toBe('STATIC');

    Event::assertDispatched(MapSolarsystemsUpsertedEvent::class, function (MapSolarsystemsUpsertedEvent $event) use ($target): bool {
        return collect($event->broadcastWith()['map_solarsystems'])
            ->contains(fn (array $system): bool => $system['id'] === $target->id && $system['alias'] === 'STATIC');
    });
});

it('updates the alias and occupier of a tracked system afterwards', function () {
    Event::fake([MapSolarsystemsUpsertedEvent::class]);

    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012011);
    $targetId = makeSolarsystem(30012012);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
        'alias' => 'C3',
    ]));

    $target = $map->mapSolarsystems()->where('solarsystem_id', $targetId)->firstOrFail();

    app(UpdateMapSolarsystemAction::class)->handle($target, [
        'alias' => 'STAGING',
        'occupier_alias' => 'Lazerhawks',
    ]);

    $target = $target->fresh()->loadMissing('details');
    expect($target->alias)->toBe('STAGING')
        ->and($target->details->occupier_alias)->toBe('Lazerhawks');

    Event::assertDispatched(MapSolarsystemsUpsertedEvent::class, function (MapSolarsystemsUpsertedEvent $event) use ($target): bool {
        return collect($event->broadcastWith()['map_solarsystems'])
            ->contains(fn (array $system): bool => $system['id'] === $target->id
                && $system['alias'] === 'STAGING'
                && $system['occupier_alias'] === 'Lazerhawks');
    });
});

it('applies an explicitly chosen ship size to the tracked connection', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012005);
    $targetId = makeSolarsystem(30012006);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
        'ship_size' => 'frigate',
    ]));

    expect(MapConnection::where('map_id', $map->id)->value('ship_size'))->toBe(ShipSize::Frigate);
});

it('falls back to the signature ship size when none is chosen explicitly', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012007);
    $targetId = makeSolarsystem(30012008);

    $signature = Signature::create([
        'map_solarsystem_id' => $origin->id,
        'signature_id' => 'ABC-123',
        'ship_size' => 'medium',
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
        'signature_id' => $signature->id,
    ]));

    expect(MapConnection::where('map_id', $map->id)->value('ship_size'))->toBe(ShipSize::Medium);
});

it('prefers the explicit ship size over the signature ship size', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012009);
    $targetId = makeSolarsystem(30012010);

    $signature = Signature::create([
        'map_solarsystem_id' => $origin->id,
        'signature_id' => 'DEF-456',
        'ship_size' => 'medium',
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
        'signature_id' => $signature->id,
        'ship_size' => 'xlarge',
    ]));

    expect(MapConnection::where('map_id', $map->id)->value('ship_size'))->toBe(ShipSize::ExtraLarge);
});

it('does not duplicate a connection tracked in the reverse direction or created by hand', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012013);
    $targetId = makeSolarsystem(30012014);

    $created = app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
    ]));
    $target = $map->mapSolarsystems()->where('solarsystem_id', $targetId)->firstOrFail();

    $reversed = app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $target->id,
        'to_solarsystem_id' => $origin->solarsystem_id,
    ]));
    $manual = app(CreateMapConnectionAction::class)->handle([
        'from_map_solarsystem_id' => $target->id,
        'to_map_solarsystem_id' => $origin->id,
    ]);

    expect(MapConnection::where('map_id', $map->id)->count())->toBe(1)
        ->and($created->wasRecentlyCreated)->toBeTrue()
        ->and($reversed->is($created))->toBeTrue()
        ->and($reversed->wasRecentlyCreated)->toBeFalse()
        ->and($manual->is($created))->toBeTrue()
        ->and($manual->wasRecentlyCreated)->toBeFalse();
});

it('applies every provided field to an existing connection', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012015);
    $target = placeMapSolarsystem($map, 30012016, 300, 300);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'mass_status' => MassStatus::Fresh,
        'lifetime' => LifetimeStatus::Healthy,
        'ship_size' => ShipSize::Large,
    ]);
    $signature = Signature::create(['map_solarsystem_id' => $origin->id, 'signature_id' => 'JKL-012']);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $target->solarsystem_id,
        'signature_id' => $signature->id,
        'alias' => 'C4',
        'mass_status' => 'reduced',
        'lifetime' => 'eol',
        'ship_size' => 'medium',
    ]));

    $connection->refresh();
    $signature->refresh();
    expect($signature->map_connection_id)->toBe($connection->id)
        ->and($signature->mass_status)->toBe(MassStatus::Reduced)
        ->and($connection->mass_status)->toBe(MassStatus::Reduced)
        ->and($connection->lifetime)->toBe(LifetimeStatus::EndOfLife)
        ->and($connection->ship_size)->toBe(ShipSize::Medium)
        ->and($target->fresh()->alias)->toBe('C4');
});

it('leaves the fields that are not provided untouched on an existing connection', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012017);
    $target = placeMapSolarsystem($map, 30012018, 300, 300);
    $target->update(['alias' => 'KEEP']);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'mass_status' => MassStatus::Reduced,
        'lifetime' => LifetimeStatus::EndOfLife,
        'ship_size' => ShipSize::Medium,
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $target->solarsystem_id,
        'alias' => null,
        'mass_status' => null,
        'lifetime' => null,
        'ship_size' => null,
    ]));

    $connection->refresh();
    expect($connection->mass_status)->toBe(MassStatus::Reduced)
        ->and($connection->lifetime)->toBe(LifetimeStatus::EndOfLife)
        ->and($connection->ship_size)->toBe(ShipSize::Medium)
        ->and($target->fresh()->alias)->toBe('KEEP');
});

it('keeps the worst mass and lifetime of an existing connection', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012019);
    $target = placeMapSolarsystem($map, 30012020, 300, 300);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'mass_status' => MassStatus::Critical,
        'lifetime' => LifetimeStatus::Critical,
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $target->solarsystem_id,
        'mass_status' => 'fresh',
        'lifetime' => 'healthy',
    ]));

    $connection->refresh();
    expect($connection->mass_status)->toBe(MassStatus::Critical)
        ->and($connection->lifetime)->toBe(LifetimeStatus::Critical);
});

it('does not reset a reduced end-of-life signature to the defaults of the connection it is linked to', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012021);
    $target = placeMapSolarsystem($map, 30012022, 300, 300);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'mass_status' => MassStatus::Fresh,
        'lifetime' => LifetimeStatus::Healthy,
    ]);
    $signature = Signature::create([
        'map_solarsystem_id' => $origin->id,
        'signature_id' => 'MNO-345',
        'mass_status' => MassStatus::Reduced,
        'lifetime' => LifetimeStatus::EndOfLife,
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $target->solarsystem_id,
        'signature_id' => $signature->id,
    ]));

    $connection->refresh();
    expect($connection->mass_status)->toBe(MassStatus::Reduced)
        ->and($connection->lifetime)->toBe(LifetimeStatus::EndOfLife)
        ->and($signature->fresh()->mass_status)->toBe(MassStatus::Reduced);
});

it('lets the signature wormhole type win over the chosen ship size on an existing connection', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012023);
    $target = placeMapSolarsystem($map, 30012024, 300, 300);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
    ]);
    $signature = Signature::create([
        'map_solarsystem_id' => $origin->id,
        'signature_id' => 'PQR-678',
        'wormhole_id' => makeWormhole()->id,
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $target->solarsystem_id,
        'signature_id' => $signature->id,
        'ship_size' => 'frigate',
    ]));

    expect($connection->fresh()->ship_size)->toBe(ShipSize::ExtraLarge);
});

it('gives the same result when the signature is linked after the connection was created', function () {
    $selection = fn (int $signature_id): array => [
        'signature_id' => $signature_id,
        'alias' => 'C5a',
        'mass_status' => 'reduced',
        'lifetime' => 'eol',
        'ship_size' => 'medium',
    ];

    $direct_map = Map::factory()->create();
    $direct_origin = placeMapSolarsystem($direct_map, 30012025);
    $direct_signature = Signature::create(['map_solarsystem_id' => $direct_origin->id, 'signature_id' => 'STU-901']);
    $direct = app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $direct_origin->id,
        'to_solarsystem_id' => makeSolarsystem(30012026),
        ...$selection($direct_signature->id),
    ]));

    $later_map = Map::factory()->create();
    $later_origin = placeMapSolarsystem($later_map, 30012025);
    $later_signature = Signature::create(['map_solarsystem_id' => $later_origin->id, 'signature_id' => 'STU-901']);
    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $later_origin->id,
        'to_solarsystem_id' => 30012026,
    ]));
    $later = app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $later_origin->id,
        'to_solarsystem_id' => 30012026,
        ...$selection($later_signature->id),
    ]));

    $summary = fn (MapConnection $connection, Signature $signature): array => [
        'mass_status' => $connection->fresh()->mass_status,
        'lifetime' => $connection->fresh()->lifetime,
        'ship_size' => $connection->fresh()->ship_size,
        'alias' => $connection->fresh()->toMapSolarsystem->alias,
        'linked' => $signature->fresh()->map_connection_id === $connection->id,
        'category' => $signature->fresh()->signature_category_id,
    ];

    expect($summary($later, $later_signature))->toBe($summary($direct, $direct_signature));
});

it('locks the connection ship size to the signature wormhole type', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30012011);
    $targetId = makeSolarsystem(30012012);

    $wormhole = makeWormhole();

    $signature = Signature::create([
        'map_solarsystem_id' => $origin->id,
        'signature_id' => 'GHI-789',
        'wormhole_id' => $wormhole->id,
    ]);

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $origin->id,
        'to_solarsystem_id' => $targetId,
        'signature_id' => $signature->id,
        'ship_size' => 'frigate',
    ]));

    expect(MapConnection::where('map_id', $map->id)->value('ship_size'))->toBe(ShipSize::ExtraLarge);
});
