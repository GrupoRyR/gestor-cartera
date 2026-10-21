<?php

namespace App\Modules\Comisiones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /comisiones/ajustes/{tipo}/{captura}/rechazar: why the capture is
 * rejected. "captura" travels back so the screen reopens the dialog of
 * the row whose reason was missing.
 */
final class RejectCaptureRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'motivo_rechazo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo_rechazo' => __('comisiones::ajustes.validacion.motivo_rechazo'),
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('motivo_rechazo'));
    }
}
