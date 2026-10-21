<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Http\Support\ParameterFields;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Validation\Rule;

/**
 * POST /comisiones/parametros/codigo: a version of a seller code (§4
 * "ParametroComision", Annex 1), or the first one of a new SAP code. The
 * person is optional (the code is then paid as itself); its id is made from
 * the name when left blank.
 */
final class StoreCodeVersionRequest extends ParameterVersionRequest
{
    public function kind(): ParameterKind
    {
        return ParameterKind::Code;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function fieldRules(): array
    {
        return [
            'clave' => ['required', 'integer', 'min:1', 'max:999999999'],
            'nombre' => ['required', 'string', 'max:120'],
            'persona_nombre' => ['nullable', 'string', 'max:120', 'required_with:persona_id'],
            'persona_id' => ParameterFields::personId(),
            'tasa_venta' => ['required', ParameterFields::percentRule()],
            'tasa_recaudo' => ['required', ParameterFields::percentRule()],
            'cumplimiento_minimo' => ['required', ParameterFields::percentRule()],
            'alcance' => ['required', 'string', Rule::in(array_map(fn (CodeScope $scope): string => $scope->value, CodeScope::cases()))],
            'usa_tramos' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function versionData(): array
    {
        return [
            'nombre' => trim((string) $this->validated('nombre')),
            'persona' => ParameterFields::person($this->validated('persona_nombre'), $this->validated('persona_id')),
            'tasa_venta' => $this->percent('tasa_venta'),
            'tasa_recaudo' => $this->percent('tasa_recaudo'),
            'cumplimiento_minimo' => $this->percent('cumplimiento_minimo'),
            'alcance' => (string) $this->validated('alcance'),
            'usa_tramos' => $this->boolean('usa_tramos'),
        ];
    }
}
