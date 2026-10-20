<?php

namespace App\Modules\Cobro\Http\Requests;

use App\Modules\Cobro\Providers\CobroServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cobro/exclusiones: the marked clients (clientes[]), the reason
 * (motivo) and the optional last day of the exclusion (vence), today or
 * later in Bogotá.
 */
final class ExcludeClientsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now(CobroServiceProvider::TIMEZONE)->format('Y-m-d');

        return [
            'clientes' => ['required', 'array', 'min:1', 'max:500'],
            'clientes.*' => ['integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:500'],
            'vence' => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$today}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clientes.required' => __('cobro::exclusiones.validacion.marca_uno'),
            'clientes.min' => __('cobro::exclusiones.validacion.marca_uno'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'clientes' => __('cobro::exclusiones.validacion.clientes'),
            'motivo' => __('cobro::exclusiones.validacion.motivo'),
            'vence' => __('cobro::exclusiones.validacion.vence'),
        ];
    }

    /**
     * @return list<int>
     */
    public function clientIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('clientes'))));
    }

    public function expiresOn(): ?CarbonImmutable
    {
        $value = $this->validated('vence');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, CobroServiceProvider::TIMEZONE)->startOfDay() : null;
    }
}
