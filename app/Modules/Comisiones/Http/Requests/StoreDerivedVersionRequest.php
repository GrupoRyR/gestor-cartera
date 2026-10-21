<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Http\Support\ParameterFields;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Validation\Rule;

/**
 * POST /comisiones/parametros/derivada: a version of a commission derived
 * from another code's sales or collections (§5.5: 81.1, 85.1), or a new one.
 */
final class StoreDerivedVersionRequest extends ParameterVersionRequest
{
    public function kind(): ParameterKind
    {
        return ParameterKind::Derived;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function fieldRules(): array
    {
        return [
            // Never a plain number: it would read as a seller code (DerivedCommissionRule).
            'clave' => ['required', 'string', 'max:20', 'regex:/^[0-9a-z]+([.-][0-9a-z]+)*$/', 'not_regex:/^\d+$/', 'not_in:nueva'],
            'etiqueta' => ['required', 'string', 'max:120'],
            'persona_nombre' => ['required', 'string', 'max:120'],
            'persona_id' => ParameterFields::personId(),
            'codigo_origen' => ['required', 'integer', 'min:1', 'max:999999999'],
            'base' => ['required', 'string', Rule::in(array_map(fn (DerivedBase $base): string => $base->value, DerivedBase::cases()))],
            'tasa' => ['required', ParameterFields::percentRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'clave.regex' => __('comisiones::parametros.validacion.clave_derivada'),
            'clave.not_regex' => __('comisiones::parametros.validacion.clave_numero'),
            'clave.not_in' => __('comisiones::parametros.validacion.clave_reservada'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function versionData(): array
    {
        return [
            'etiqueta' => trim((string) $this->validated('etiqueta')),
            'persona' => ParameterFields::person($this->validated('persona_nombre'), $this->validated('persona_id')),
            'codigo_origen' => (int) $this->validated('codigo_origen'),
            'base' => (string) $this->validated('base'),
            'tasa' => $this->percent('tasa'),
        ];
    }
}
