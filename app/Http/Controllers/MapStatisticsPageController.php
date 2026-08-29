<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\MapInfoResource;
use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class MapStatisticsPageController extends Controller
{
    public function __construct(
        #[CurrentUser] private readonly User $user,
    ) {}

    /**
     * Show the maintainer leaderboard page.
     *
     * Stub for S5: registers the route MaintainerPodiumEmbed links to, so posting the
     * monthly podium doesn't throw a RouteNotFoundException. S6 fills in the leaderboard
     * props and the Vue page.
     */
    public function show(Map $map): Response
    {
        Gate::authorize('viewCharacters', $map);

        return Inertia::render('maps/settings/ShowStatistics', [
            'map' => $map->toResource(MapInfoResource::class),
            'is_owner' => Gate::allows('delete', $map),
            'permission' => $map->getUserPermission($this->user)?->value,
        ]);
    }
}
