<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportMapRequest extends FormRequest
{
    public function __construct(#[RouteParameter('map')] public Map $map)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('updateSettings', $this->map)) {
            return false;
        }

        if (in_array('access', (array) $this->input('sections', []), true)) {
            return (bool) $user->can('manageAccess', $this->map);
        }

        return true;
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
        ];
    }
}
