{{-- The fields of a new OBR/Escudos sale (§5.5). $codes: code => label. --}}
<x-ui.select name="codigo" :label="__('comisiones::ajustes.obr.codigo')" :options="$codes" :placeholder="__('comisiones::ajustes.obr.codigo_elige')" :required="true" />
<x-ui.textarea name="descripcion" :label="__('comisiones::ajustes.obr.descripcion')" :rows="2" :required="true" maxlength="500" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input name="valor_vendido" :label="__('comisiones::ajustes.obr.valor_vendido')" prefix="$" inputmode="decimal" autocomplete="off" :required="true" />
    <x-ui.input name="tasa" :label="__('comisiones::ajustes.obr.tasa')" :hint="__('comisiones::ajustes.obr.tasa_ayuda')" suffix="%" inputmode="decimal" autocomplete="off" :required="true" />
</div>
