<?php

namespace App\Modules\Asesores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The form to cancel a commitment: the reason is required, because Core never
 * deletes a commitment and the reason is what explains it later.
 */
final class CancelCommitmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['motivo' => __('asesores::compromisos.campos.motivo.nombre')];
    }

    public function reason(): string
    {
        return trim($this->string('motivo')->toString());
    }
}
