<?php

namespace App\Modules\Cobro\Http\Requests;

use App\Modules\Cobro\Domain\Signatures\SignaturePerson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /cobro/firma/{empresa}: who signs the company's Cobro mails. The
 * name (nombre) and title (cargo) are required; the mobile (movil) is a
 * phone number, digits with an optional leading "+", spaces, dashes and
 * parentheses; the PBX extension (extension) is optional, up to 6 digits.
 */
final class UpdateSignatureRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'cargo' => ['required', 'string', 'max:100'],
            'movil' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{6,28}$/'],
            'extension' => ['nullable', 'string', 'regex:/^[0-9]{1,6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => __('cobro::firma.validacion.nombre'),
            'nombre.max' => __('cobro::firma.validacion.largo'),
            'cargo.required' => __('cobro::firma.validacion.cargo'),
            'cargo.max' => __('cobro::firma.validacion.largo'),
            'movil.required' => __('cobro::firma.validacion.movil'),
            'movil.max' => __('cobro::firma.validacion.movil'),
            'movil.regex' => __('cobro::firma.validacion.movil'),
            'extension.regex' => __('cobro::firma.validacion.extension'),
        ];
    }

    public function person(): SignaturePerson
    {
        return SignaturePerson::fromArray([
            'name' => $this->string('nombre')->toString(),
            'title' => $this->string('cargo')->toString(),
            'mobile' => $this->string('movil')->toString(),
            'extension' => $this->string('extension')->toString(),
        ]);
    }
}
