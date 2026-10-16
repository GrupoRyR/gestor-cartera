<?php

namespace App\Modules\Cartera\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /cartera/compromisos/{commitment}: new date, value or notes of a
 * pending commitment. A field left empty stays as it was. The fields are
 * nested under "edicion_{id}" because the client file has one edit form per
 * commitment.
 */
final class UpdateCommitmentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $form = $this->form();

        return [
            "{$form}.fecha" => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            "{$form}.valor" => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            "{$form}.notas" => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $form = $this->form();

        return [
            "{$form}.fecha" => 'fecha de pago',
            "{$form}.valor" => 'valor comprometido',
            "{$form}.notas" => 'notas',
        ];
    }

    /**
     * Name of the field group of this commitment's form.
     */
    public function form(): string
    {
        return 'edicion_'.(int) $this->route('commitment');
    }
}
