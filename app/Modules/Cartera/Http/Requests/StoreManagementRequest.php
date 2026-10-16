<?php

namespace App\Modules\Cartera\Http\Requests;

use App\Modules\Cartera\Domain\Management\CallResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /cartera/clientes/{client}/gestiones: one entry of the client's log,
 * of the kind named by "tipo" (llamada, nota or descuento).
 *
 * Each kind's fields are nested under its own name (llamada[resultado],
 * nota[detalle], descuento[valor]) so the three forms of the client file can
 * share one page without sharing old input or error messages.
 */
final class StoreManagementRequest extends FormRequest
{
    public const array KINDS = ['llamada', 'nota', 'descuento'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = ['tipo' => ['required', Rule::in(self::KINDS)]];

        return $rules + match ($this->input('tipo')) {
            'llamada' => [
                'llamada.resultado' => ['required', Rule::enum(CallResult::class)],
                'llamada.detalle' => ['nullable', 'string', 'max:2000'],
            ],
            'nota' => [
                'nota.detalle' => ['required', 'string', 'max:2000'],
            ],
            'descuento' => [
                'descuento.valor' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
                'descuento.detalle' => ['nullable', 'string', 'max:2000'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de gestión',
            'llamada.resultado' => 'resultado',
            'llamada.detalle' => 'detalle',
            'nota.detalle' => 'nota',
            'descuento.valor' => 'valor del descuento',
            'descuento.detalle' => 'detalle',
        ];
    }

    /**
     * A form that fails goes back to the client file, whichever page the
     * request came from.
     */
    protected function getRedirectUrl(): string
    {
        return route('cartera.clientes.show', (int) $this->route('client'));
    }
}
