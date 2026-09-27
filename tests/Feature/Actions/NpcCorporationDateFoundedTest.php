<?php

declare(strict_types=1);

use App\Actions\EnsureOrganisationExistsAction;
use App\Models\Corporation;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\Esi;

function makeEsiCorporationFoundedOn(?string $dateFounded): NicolasKion\Esi\DTO\Corporation
{
    return new NicolasKion\Esi\DTO\Corporation(
        alliance_id: null,
        ceo_id: 3004421,
        creator_id: 1,
        date_founded: $dateFounded,
        description: 'An NPC corporation',
        faction_id: null,
        home_station_id: 60004588,
        member_count: 109383,
        name: 'Brutor Tribe',
        shares: 100000000,
        tax_rate: 0.11,
        ticker: 'B',
        url: '',
        war_eligible: false,
    );
}

it('stores an NPC corporation whose founding date is empty', function () {
    // ESI reports NPC corporations with an empty founding date rather than
    // omitting it, and MySQL in strict mode rejects '' for a datetime column.
    // Adding a character in a starter corporation used to fail on this.
    $esi = $this->mock(Esi::class);
    $esi->shouldReceive('getCorporation')
        ->with(1000049)
        ->once()
        ->andReturn(new EsiResult(data: makeEsiCorporationFoundedOn('')));

    app(EnsureOrganisationExistsAction::class)->ensureCorporationExists(1000049);

    $corporation = Corporation::query()->find(1000049);

    expect($corporation)->not->toBeNull()
        ->and($corporation->name)->toBe('Brutor Tribe')
        ->and($corporation->date_founded)->toBeNull();
});

it('keeps a real founding date', function () {
    $esi = $this->mock(Esi::class);
    $esi->shouldReceive('getCorporation')
        ->with(98000202)
        ->once()
        ->andReturn(new EsiResult(data: makeEsiCorporationFoundedOn('2003-01-01T00:00:00Z')));

    app(EnsureOrganisationExistsAction::class)->ensureCorporationExists(98000202);

    expect(Corporation::query()->find(98000202)->date_founded->toDateString())->toBe('2003-01-01');
});
