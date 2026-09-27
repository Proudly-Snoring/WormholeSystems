<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapTransfer\ImportMapAsNewAction;
use App\Actions\MapTransfer\ParseMapExportFileAction;
use App\Http\Requests\ImportNewMapRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class MapImportController extends Controller
{
    public function __construct(#[CurrentUser] private readonly User $user) {}

    /**
     * @throws Throwable
     */
    public function store(
        ImportNewMapRequest $request,
        ParseMapExportFileAction $parseAction,
        ImportMapAsNewAction $importAction,
    ): RedirectResponse {
        $payload = $parseAction->handle($request->file('file'), $request->validated('sections'), for_new_map: true);

        $map = $importAction->handle(
            $this->user->active_character,
            $payload,
            $request->validated('name'),
        );

        return to_route('maps.settings.general.show', $map)
            ->notify('Map imported.', sprintf('The map "%s" has been created from the import file.', $map->name));
    }
}
