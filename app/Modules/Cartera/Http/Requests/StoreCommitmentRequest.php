<?php

namespace App\Modules\Cartera\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cartera/clientes/{client}/compromisos: a new payment commitment, with
 * its fields nested under "compromiso" so the form shares the client file
 * with the other forms. The date is today or later: a promise for a day that
 * has passed would break on arrival.
 */
final class StoreCommitmentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'compromiso.fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'compromiso.valor' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'compromiso.notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'compromiso.fecha' => 'fecha de pago',
            'compromiso.valor' => 'valor comprometido',
            'compromiso.notas' => 'notas',
        ];
    }

    /**
     * A form that fails goes back to the client file, whichever page sent it.
     */
    protected function getRedirectUrl(): string
    {
        return route('cartera.clientes.show', (int) $this->route('client'));
    }
}
