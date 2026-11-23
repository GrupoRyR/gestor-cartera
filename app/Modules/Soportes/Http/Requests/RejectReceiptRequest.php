<?php

namespace App\Modules\Soportes\Http\Requests;

/**
 * PATCH /soportes/{soporte}/rechazar: the reason ("motivo", required, up to
 * 500 characters), which the advisor sees.
 */
final class RejectReceiptRequest extends ReviewReceiptRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['motivo' => __('soportes::revision.rechazar.motivo')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['motivo.required' => __('soportes::revision.rechazar.motivo_requerido')];
    }

    public function reason(): string
    {
        return $this->string('motivo')->trim()->toString();
    }
}
