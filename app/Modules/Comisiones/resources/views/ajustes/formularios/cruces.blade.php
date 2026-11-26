{{-- The fields of a new manual crossing (§5.4). $codes: code => label. --}}
<p class="text-[0.9375rem] text-fg-muted">{{ __('comisiones::ajustes.cruces.ayuda') }}</p>
<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.select name="vendedor" :label="__('comisiones::ajustes.cruces.vendedor')" :options="$codes" :placeholder="__('comisiones::ajustes.cruces.vendedor_elige')" :required="true" />
    <x-ui.select name="tipo_cruce" :label="__('comisiones::ajustes.cruces.tipo')" :options="trans('comisiones::ajustes.cruces.tipos')" :placeholder="__('comisiones::ajustes.cruces.tipo_elige')" :required="true" />
    <x-ui.input name="factura" :label="__('comisiones::ajustes.cruces.factura')" maxlength="50" :required="true" />
    <x-ui.input name="fecha_factura" type="date" :label="__('comisiones::ajustes.cruces.fecha_factura')" :required="true" />
    <x-ui.input name="fecha_cruce" type="date" :label="__('comisiones::ajustes.cruces.fecha_cruce')" :required="true" />
    <x-ui.input name="codigo_cliente" :label="__('comisiones::ajustes.cruces.codigo_cliente')" maxlength="50" :required="true" />
    <div class="sm:col-span-2">
        <x-ui.input name="razon_social" :label="__('comisiones::ajustes.cruces.razon_social')" maxlength="255" :required="true" />
    </div>
    <x-ui.input name="tercero" :label="__('comisiones::ajustes.cruces.tercero')" :hint="__('comisiones::ajustes.cruces.tercero_ayuda')" maxlength="50" />
    <x-ui.money-input name="importe_original" :decimals="2" :label="__('comisiones::ajustes.cruces.importe_original')" :required="true" />
    <x-ui.money-input name="valor_cruzado" :decimals="2" :label="__('comisiones::ajustes.cruces.valor_cruzado')" :required="true" />
    <x-ui.money-input name="iva_factura" :decimals="2" :label="__('comisiones::ajustes.cruces.iva_factura')" :hint="__('comisiones::ajustes.cruces.iva_factura_ayuda')" />
    <x-ui.money-input name="valor_sin_iva" :decimals="2" :label="__('comisiones::ajustes.cruces.valor_sin_iva')" :hint="__('comisiones::ajustes.cruces.valor_sin_iva_ayuda')" />
</div>
