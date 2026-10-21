<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Http\Support\ParameterFields;
use App\Modules\Comisiones\Http\Support\ParameterKind;

/**
 * POST /comisiones/parametros/equipo: a version of a channel director's
 * team (§4 "Equipo", §5.6), or a new team: its key, channel, director,
 * optional associated code (§14.4), rate and member codes typed as a list
 * («14, 15, 16»).
 */
final class StoreTeamVersionRequest extends ParameterVersionRequest
{
    public function kind(): ParameterKind
    {
        return ParameterKind::Team;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function fieldRules(): array
    {
        return [
            // Never a plain number: it would read as a seller code (DirectorTeam).
            'clave' => ['required', 'string', 'max:60', 'regex:'.ParameterFields::SLUG, 'not_regex:/^\d+$/', 'not_in:nueva'],
            'canal' => ['required', 'string', 'max:120'],
            'director_nombre' => ['required', 'string', 'max:120'],
            'director_id' => ParameterFields::personId(),
            'codigo_asociado' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'tasa' => ['required', ParameterFields::percentRule()],
            'miembros' => ['required', 'string', 'max:1000', ParameterFields::codesRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'clave.regex' => __('comisiones::parametros.validacion.clave_equipo'),
            'clave.not_regex' => __('comisiones::parametros.validacion.clave_numero'),
            'clave.not_in' => __('comisiones::parametros.validacion.clave_reservada'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function versionData(): array
    {
        $associated = $this->validated('codigo_asociado');

        return [
            'canal' => trim((string) $this->validated('canal')),
            'director' => ParameterFields::person($this->validated('director_nombre'), $this->validated('director_id')),
            'codigo_asociado' => $associated === null ? null : (int) $associated,
            'tasa' => $this->percent('tasa'),
            'miembros' => ParameterFields::codes($this->validated('miembros')) ?? [],
        ];
    }
}
