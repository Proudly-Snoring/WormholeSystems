<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintainerCharacterStatResource;
use App\Http\Resources\MaintainerEntryResource;
use App\Models\Map;
use App\Services\Statistics\MaintainerPeriod;
use App\Services\Statistics\MaintainerReportReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class MapStatisticsController extends Controller
{
    public function __construct(
        private readonly MaintainerReportReader $reader,
    ) {}

    public function aggregated(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('viewCharacters', $map);

        $period = $this->resolvePeriod($request);

        return response()->json([
            'data' => $this->reader->aggregated($map, $period)->toResourceCollection(MaintainerEntryResource::class),
        ]);
    }

    public function details(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('viewCharacters', $map);

        $period = $this->resolvePeriod($request);

        return response()->json([
            'data' => $this->reader->details($map, $period)->toResourceCollection(MaintainerCharacterStatResource::class),
        ]);
    }

    private function resolvePeriod(Request $request): MaintainerPeriod
    {
        $value = $request->query('period');

        if ($value !== null && ! is_string($value)) {
            abort(422, 'Invalid period.');
        }

        try {
            $period = is_string($value) && $value !== '' ? MaintainerPeriod::fromString($value) : MaintainerPeriod::current();
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid period.');
        }

        $isSelectable = collect(MaintainerPeriod::selectable())
            ->contains(fn (MaintainerPeriod $selectable): bool => $selectable->toString() === $period->toString());

        if (! $isSelectable) {
            abort(404);
        }

        return $period;
    }
}
