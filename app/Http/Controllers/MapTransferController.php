<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapTransfer\ExportMapAction;
use App\Actions\MapTransfer\ImportMapAction;
use App\Actions\MapTransfer\ParseMapExportFileAction;
use App\Http\Requests\ExportMapRequest;
use App\Http\Requests\ImportMapRequest;
use App\Http\Resources\MapInfoResource;
use App\Models\Map;
use App\Models\Signature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class MapTransferController extends Controller
{
    public function show(Map $map): Response
    {
        Gate::authorize('manageAccess', $map);

        return Inertia::render('maps/settings/ShowTransfer', [
            'map' => $map->toResource(MapInfoResource::class),
            'permission' => $map->getUserPermission(auth()->user())?->value,
            'counts' => [
                'access' => $map->mapAccessors()->where('is_owner', false)->count(),
                'solarsystems' => $map->mapSolarsystemDetails()->count(),
                'connections' => $map->mapConnections()->count(),
                'signatures' => Signature::query()->whereIn('map_solarsystem_id', $map->mapSolarsystems()->select('id'))->count(),
                'routes' => $map->mapRouteSolarsystems()->count() + $map->mapIgnoredSolarsystems()->count(),
            ],
        ]);
    }

    public function export(ExportMapRequest $request, Map $map, ExportMapAction $action): StreamedResponse
    {
        $payload = $action->handle($map, $request->validated('sections'));

        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            sprintf('%s-export-%s.json', $map->slug, now()->format('Y-m-d')),
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @throws Throwable
     */
    public function import(
        ImportMapRequest $request,
        Map $map,
        ParseMapExportFileAction $parseAction,
        ImportMapAction $importAction,
    ): RedirectResponse {
        $payload = $parseAction->handle($request->file('file'), $request->validated('sections'));

        $summary = $importAction->handle($map, $payload);

        return back()->notify('Import complete.', $summary->describe());
    }
}
