@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The fields of a director team version (§4 "Equipo", §5.6). $key, $isNew, $values: the stored "datos" to start from. --}}
@if ($isNew)
    <div class="max-w-sm">
        <x-ui.input name="clave" :label="__('comisiones::parametros.campos.clave')" :hint="__('comisiones::parametros.formulario.equipo.clave_ayuda')" autocomplete="off" :required="true" maxlength="60" />
    </div>
@else
    <input type="hidden" name="clave" value="{{ $key }}">
@endif

<x-ui.input name="canal" :label="__('comisiones::parametros.campos.canal')" :hint="__('comisiones::parametros.formulario.equipo.canal_ayuda')" :value="$values['canal'] ?? null" :required="true" maxlength="120" />

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input name="director_nombre" :label="__('comisiones::parametros.formulario.equipo.director_nombre')" :value="ParameterValues::person($values['director'] ?? null)" :required="true" maxlength="120" />
    <x-ui.input name="director_id" :label="__('comisiones::parametros.formulario.equipo.director_id')" :hint="__('comisiones::parametros.formulario.equipo.director_id_ayuda')" :value="ParameterValues::personId($values['director'] ?? null)" autocomplete="off" maxlength="60" />
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input name="codigo_asociado" :label="__('comisiones::parametros.campos.codigo_asociado')" :hint="__('comisiones::parametros.formulario.equipo.codigo_asociado_ayuda')" :value="isset($values['codigo_asociado']) ? (string) $values['codigo_asociado'] : null" inputmode="numeric" autocomplete="off" />
    <x-ui.input name="tasa" :label="__('comisiones::parametros.campos.tasa')" :hint="__('comisiones::parametros.formulario.equipo.tasa_ayuda')" :value="ParameterValues::percentInput($values['tasa'] ?? null)" suffix="%" inputmode="decimal" autocomplete="off" :required="true" />
</div>

<x-ui.input name="miembros" :label="__('comisiones::parametros.campos.miembros')" :hint="__('comisiones::parametros.formulario.equipo.miembros_ayuda')" :value="isset($values['miembros']) ? ParameterValues::members($values['miembros']) : null" autocomplete="off" :required="true" maxlength="1000" />
