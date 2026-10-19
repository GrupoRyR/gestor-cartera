<?php

namespace App\Modules\Bancos\Http\Requests;

use App\Modules\Bancos\Domain\Movement\MovementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /bancos/movimiento/{movimiento}: the new status and, when the payment
 * was applied in SAP, the cash receipt number(s). The route already checked
 * bancos.apply.
 */
final class ApplyMovementRequest extends FormRequest
{
    public const int MAX_RECEIPT = 60;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::enum(MovementStatus::class)],
            'recibo' => [
                'nullable',
                'required_if:estado,'.MovementStatus::Applied->value,
                'string',
                'max:'.self::MAX_RECEIPT,
                // Digits and letters, with dashes, slashes, commas and spaces between them: "4521" or "4521, 4522".
                'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/, ]*$/',
            ],
            'volver' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('bancos::movimientos.atributos');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recibo.required_if' => __('bancos::movimientos.validacion.recibo_requerido'),
            'recibo.regex' => __('bancos::movimientos.validacion.recibo_formato'),
        ];
    }

    public function status(): MovementStatus
    {
        return MovementStatus::from($this->string('estado')->toString());
    }

    public function receipt(): ?string
    {
        $receipt = trim($this->string('recibo')->toString());

        return $receipt === '' ? null : $receipt;
    }
}
