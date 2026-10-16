<?php

namespace App\Modules\Core\Http\Requests\Companies;

use App\Modules\Core\Domain\Status\StatusParameters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /empresas/{empresa}: the Parámetros form. Both values are whole days:
 * "grace_days" from 0 to MAX_GRACE_DAYS and "due_soon_days" from 0 to
 * MAX_DUE_SOON_DAYS.
 */
final class UpdateCompanyParametersRequest extends FormRequest
{
    public const int MAX_GRACE_DAYS = 120;

    public const int MAX_DUE_SOON_DAYS = 60;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'grace_days' => ['required', 'integer', 'min:0', 'max:'.self::MAX_GRACE_DAYS],
            'due_soon_days' => ['required', 'integer', 'min:0', 'max:'.self::MAX_DUE_SOON_DAYS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'grace_days' => __('core::empresas.parametros.campos.dias_gracia'),
            'due_soon_days' => __('core::empresas.parametros.campos.ventana'),
        ];
    }

    public function parameters(): StatusParameters
    {
        return new StatusParameters($this->integer('grace_days'), $this->integer('due_soon_days'));
    }
}
