<?php

declare(strict_types=1);

namespace App\Actions\MapTransfer;

use App\Actions\Map\CreateMapAction;
use App\Models\Character;
use App\Models\Map;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ImportMapAsNewAction
{
    public function __construct(
        private CreateMapAction $createMapAction,
        private ImportMapAction $importMapAction,
    ) {}

    /**
     * Create a fresh map owned by the importing character and load the payload
     * into it. When the file carries a routes section, the seeded trade-hub
     * defaults make way for the file's list.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws Throwable
     */
    public function handle(Character $character, array $payload, ?string $name = null): Map
    {
        return DB::transaction(function () use ($character, $payload, $name): Map {
            $map = $this->createMapAction->handle($character, [
                'name' => $name ?? $payload['map_name'],
            ]);

            if (isset($payload['sections']['routes'])) {
                $map->mapRouteSolarsystems()->delete();
            }

            if (isset($payload['sections']['settings'])) {
                $payload['sections']['settings']['name'] = $name ?? $payload['sections']['settings']['name'];
            }

            $this->importMapAction->handle($map, $payload);

            return $map;
        }, 5);
    }
}
