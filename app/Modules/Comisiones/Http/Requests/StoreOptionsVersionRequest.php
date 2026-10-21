<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Http\Support\OptionFields;
use App\Modules\Comisiones\Http\Support\ParameterFields;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Validation\Rule;

/**
 * POST /comisiones/parametros/opciones: a version of the settlement options,
 * the answers to the open points 14.2–14.7 (SettlementOptions). The VAT
 * rate only matters when sales include VAT; left blank it is 19 %.
 */
final class StoreOptionsVersionRequest extends ParameterVersionRequest
{
    /** The VAT rate when none is typed (SettlementOptions). */
    public const string DEFAULT_VAT = '19';

    public function kind(): ParameterKind
    {
        return ParameterKind::Options;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function fieldRules(): array
    {
        $rules = [];

        foreach (array_keys(OptionFields::FIELDS) as $field) {
            $rules[$field] = match (OptionFields::kind($field)) {
                OptionFields::BOOLEAN => ['nullable', 'boolean'],
                OptionFields::PERCENT => ['nullable', ParameterFields::percentRule()],
                default => ['required', 'string', Rule::in(array_keys(OptionFields::choices($field)))],
            };
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function versionData(): array
    {
        $data = [];

        foreach (array_keys(OptionFields::FIELDS) as $field) {
            $data[$field] = match (OptionFields::kind($field)) {
                OptionFields::BOOLEAN => $this->boolean($field),
                OptionFields::PERCENT => ParameterFields::percent($this->validated($field)) ?? self::DEFAULT_VAT,
                default => (string) $this->validated($field),
            };
        }

        return $data;
    }
}
