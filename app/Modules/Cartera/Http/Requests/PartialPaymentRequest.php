<?php

namespace App\Modules\Cartera\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cartera/compromisos/{commitment}/abonar: what the client paid of a
 * commitment, in pesos like a new commitment's value, and an optional note.
 * Whether it is below the commitment value is CompromisoService's call. The
 * fields sit under "abono_{id}" as each commitment row has its own form.
 */
final class PartialPaymentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $form = $this->form();

        return [
            "{$form}.valor" => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            "{$form}.nota" => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $form = $this->form();

        return [
            "{$form}.valor" => 'valor pagado',
            "{$form}.nota" => 'nota',
        ];
    }

    /**
     * Name of the field group of this commitment's form.
     */
    public function form(): string
    {
        return 'abono_'.(int) $this->route('commitment');
    }
}
