{{-- The fields of a new reimbursement or deduction (§5.5). $codes: code => label. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.select name="codigo" :label="__('comisiones::ajustes.reintegros.codigo')" :options="$codes" :placeholder="__('comisiones::ajustes.reintegros.codigo_elige')" :required="true" />
    <x-ui.select name="tipo" :label="__('comisiones::ajustes.reintegros.tipo')" :options="trans('comisiones::ajustes.reintegros.tipos')" :placeholder="__('comisiones::ajustes.reintegros.tipo_elige')" :required="true" />
</div>
<x-ui.money-input name="valor" :decimals="2" :label="__('comisiones::ajustes.reintegros.valor')" :required="true" />
<x-ui.textarea name="descripcion" :label="__('comisiones::ajustes.reintegros.descripcion')" :rows="2" :required="true" maxlength="500" />
