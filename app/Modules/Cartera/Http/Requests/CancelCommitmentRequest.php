<?php

namespace App\Modules\Cartera\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cartera/compromisos/{commitment}/anular: the reason a commitment is
 * cancelled, which the log keeps. The field sits under "anulacion_{id}" as
 * the client file has one cancel form per commitment.
 */
final class CancelCommitmentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            $this->form().'.motivo' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            $this->form().'.motivo' => 'motivo',
        ];
    }

    /**
     * Name of the field group of this commitment's form.
     */
    public function form(): string
    {
        return 'anulacion_'.(int) $this->route('commitment');
    }
}
