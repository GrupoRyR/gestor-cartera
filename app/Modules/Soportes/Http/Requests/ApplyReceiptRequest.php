<?php

namespace App\Modules\Soportes\Http\Requests;

/**
 * PATCH /soportes/{soporte}/aplicar: the optional cash receipt number
 * ("recibo", up to 60 characters).
 */
final class ApplyReceiptRequest extends ReviewReceiptRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recibo' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['recibo' => __('soportes::revision.aplicar.recibo')];
    }

    public function receiptNumber(): ?string
    {
        $number = $this->string('recibo')->trim()->toString();

        return $number === '' ? null : $number;
    }
}
