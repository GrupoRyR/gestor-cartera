@use('App\Modules\Comisiones\Domain\Parameters\DerivedBase')
@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The fields of a derived commission version (§5.5: 81.1, 85.1). $key, $isNew, $values: the stored "datos" to start from. --}}
@php
    $bases = [];
    foreach (DerivedBase::cases() as $base) {
        $bases[$base->value] = $base->label();
    }
@endphp

@if ($isNew)
    <div class="max-w-xs">
        <x-ui.input name="clave" :label="__('comisiones::parametros.campos.clave')" :hint="__('comisiones::parametros.formulario.derivada.clave_ayuda')" autocomplete="off" :required="true" maxlength="20" />
    </div>
@else
    <input type="hidden" name="clave" value="{{ $key }}">
@endif

<x-ui.input name="etiqueta" :label="__('comisiones::parametros.campos.etiqueta')" :hint="__('comisiones::parametros.formulario.derivada.etiqueta_ayuda')" :value="$values['etiqueta'] ?? null" :required="true" maxlength="120" />

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input name="persona_nombre" :label="__('comisiones::parametros.formulario.derivada.persona_nombre')" :value="ParameterValues::person($values['persona'] ?? null)" :required="true" maxlength="120" />
    <x-ui.input name="persona_id" :label="__('comisiones::parametros.formulario.derivada.persona_id')" :hint="__('comisiones::parametros.formulario.derivada.persona_id_ayuda')" :value="ParameterValues::personId($values['persona'] ?? null)" autocomplete="off" maxlength="60" />
</div>

<div class="grid gap-4 sm:grid-cols-3">
    <x-ui.input name="codigo_origen" :label="__('comisiones::parametros.campos.codigo_origen')" :hint="__('comisiones::parametros.formulario.derivada.codigo_origen_ayuda')" :value="isset($values['codigo_origen']) ? (string) $values['codigo_origen'] : null" inputmode="numeric" autocomplete="off" :required="true" />
    <x-ui.select name="base" :label="__('comisiones::parametros.campos.base')" :hint="__('comisiones::parametros.formulario.derivada.base_ayuda')" :options="$bases" :value="$values['base'] ?? DerivedBase::Sales->value" :required="true" />
    <x-ui.input name="tasa" :label="__('comisiones::parametros.campos.tasa')" :value="ParameterValues::percentInput($values['tasa'] ?? null)" suffix="%" inputmode="decimal" autocomplete="off" :required="true" />
</div>
