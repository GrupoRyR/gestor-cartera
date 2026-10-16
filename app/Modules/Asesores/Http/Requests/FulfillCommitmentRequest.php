<?php

namespace App\Modules\Asesores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The form to mark a commitment as fulfilled: an optional note, such as how
 * the client paid.
 */
final class FulfillCommitmentRequest extends FormRequest
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
            'nota' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nota' => __('asesores::compromisos.campos.nota.nombre')];
    }

    public function note(): ?string
    {
        $note = trim($this->string('nota')->toString());

        return $note === '' ? null : $note;
    }
}
