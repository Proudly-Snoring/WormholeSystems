<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportNewMapRequest extends FormRequest
{
    public function authorize(#[CurrentUser] User $user): bool
    {
        return $user->can('create', Map::class) && $user->active_character !== null;
    }

    /**
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'mimetypes:application/json,text/plain'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(['settings', 'access', 'solarsystems', 'connections', 'signatures', 'routes'])],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
