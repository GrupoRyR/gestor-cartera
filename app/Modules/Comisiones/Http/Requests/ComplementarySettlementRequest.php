<?php

namespace App\Modules\Comisiones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /comisiones/periodos/{periodo}/complementaria: why the closed
 * period is settled again (motivo, §6 step 7).
 */
final class ComplementarySettlementRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo' => __('comisiones::liquidacion.validacion.motivo'),
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('motivo'));
    }
}
