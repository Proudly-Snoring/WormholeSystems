<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExportMapRequest extends FormRequest
{
    public function __construct(#[RouteParameter('map')] public Map $map)
    {
        parent::__construct();
    }

    /**
     * Exports carry notes and the full access roster, so only managers may pull them.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageAccess', $this->map);
    }

    /**
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(['settings', 'access', 'solarsystems', 'connections', 'signatures', 'routes'])],
        ];
    }
}
