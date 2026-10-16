<?php

namespace App\Modules\Asesores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The form to add a note to the log of a client.
 */
final class StoreNoteRequest extends FormRequest
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
            'nota' => ['required', 'string', 'max:1000'],
            // Which form failed, so the page can open its dialog again.
            'formulario' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nota' => __('asesores::ficha.bitacora.nota.nombre')];
    }

    public function text(): string
    {
        return $this->string('nota')->toString();
    }
}
