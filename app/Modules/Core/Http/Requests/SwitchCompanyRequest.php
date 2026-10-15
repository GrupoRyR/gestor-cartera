<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The company selector of the layout: "empresa" is SGX, SDC or "grupo"
 * (the group view).
 */
final class SwitchCompanyRequest extends FormRequest
{
    private const string GROUP = 'grupo';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'empresa' => ['required', 'string', Rule::in([...array_keys(CompanyCode::options()), self::GROUP])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['empresa' => __('core::empresas.campo')];
    }

    /**
     * The chosen company; null for the group view.
     */
    public function company(): ?CompanyCode
    {
        $choice = $this->string('empresa')->value();

        return $choice === self::GROUP ? null : CompanyCode::from($choice);
    }
}
