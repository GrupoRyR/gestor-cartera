{{--
    The fields of a new reassignment (§5.4). $codes: code => label;
    $choices: clave => label of the crossings of the period.
--}}
<p class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::ajustes.reasignaciones.ayuda') }}</p>
<x-ui.select name="clave_cruce" :label="__('comisiones::ajustes.reasignaciones.cruce')" :options="$choices" :placeholder="__('comisiones::ajustes.reasignaciones.cruce_elige')" :required="true" />
<x-ui.select name="codigo" :label="__('comisiones::ajustes.reasignaciones.codigo')" :options="$codes" :placeholder="__('comisiones::ajustes.reasignaciones.codigo_elige')" :required="true" />
<x-ui.textarea name="motivo" :label="__('comisiones::ajustes.reasignaciones.motivo')" :rows="2" :required="true" maxlength="500" />
