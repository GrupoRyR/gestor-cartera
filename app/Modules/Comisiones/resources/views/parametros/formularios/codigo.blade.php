@use('App\Modules\Comisiones\Domain\Parameters\CodeScope')
@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The fields of a seller code version (§4, Annex 1). $key, $isNew, $values: the stored "datos" to start from. --}}
@php
    $scopes = [];
    foreach (CodeScope::cases() as $scope) {
        $scopes[$scope->value] = $scope->label();
    }
@endphp

@if ($isNew)
    <div class="max-w-xs">
        <x-ui.input name="clave" :label="__('comisiones::parametros.campos.codigo')" :hint="__('comisiones::parametros.formulario.codigo.clave_ayuda')" inputmode="numeric" autocomplete="off" :required="true" />
    </div>
@else
    <input type="hidden" name="clave" value="{{ $key }}">
@endif

<x-ui.input name="nombre" :label="__('comisiones::parametros.campos.nombre')" :hint="__('comisiones::parametros.formulario.codigo.nombre_ayuda')" :value="$values['nombre'] ?? null" :required="true" maxlength="120" />

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input name="persona_nombre" :label="__('comisiones::parametros.formulario.codigo.persona_nombre')" :hint="__('comisiones::parametros.formulario.codigo.persona_nombre_ayuda')" :value="ParameterValues::person($values['persona'] ?? null)" maxlength="120" />
    <x-ui.input name="persona_id" :label="__('comisiones::parametros.formulario.codigo.persona_id')" :hint="__('comisiones::parametros.formulario.codigo.persona_id_ayuda')" :value="ParameterValues::personId($values['persona'] ?? null)" autocomplete="off" maxlength="60" />
</div>

<div class="grid gap-4 sm:grid-cols-3">
    <x-ui.input name="tasa_venta" :label="__('comisiones::parametros.campos.tasa_venta')" :hint="__('comisiones::parametros.formulario.codigo.tasa_ayuda')" :value="ParameterValues::percentInput($values['tasa_venta'] ?? null)" suffix="%" inputmode="decimal" autocomplete="off" :required="true" />
    <x-ui.input name="tasa_recaudo" :label="__('comisiones::parametros.campos.tasa_recaudo')" :hint="__('comisiones::parametros.formulario.codigo.tasa_ayuda')" :value="ParameterValues::percentInput($values['tasa_recaudo'] ?? null)" suffix="%" inputmode="decimal" autocomplete="off" :required="true" />
    <x-ui.input name="cumplimiento_minimo" :label="__('comisiones::parametros.campos.cumplimiento_minimo')" :hint="__('comisiones::parametros.formulario.codigo.cumplimiento_ayuda')" :value="ParameterValues::percentInput($values['cumplimiento_minimo'] ?? null)" suffix="%" inputmode="decimal" autocomplete="off" :required="true" />
</div>

<x-ui.select name="alcance" :label="__('comisiones::parametros.campos.alcance')" :hint="__('comisiones::parametros.formulario.codigo.alcance_ayuda')" :options="$scopes" :value="$values['alcance'] ?? CodeScope::Regular->value" :required="true" />

<x-ui.checkbox name="usa_tramos" :label="__('comisiones::parametros.formulario.codigo.usa_tramos')" :hint="__('comisiones::parametros.formulario.codigo.usa_tramos_ayuda')" :checked="($values['usa_tramos'] ?? true) !== false" />
