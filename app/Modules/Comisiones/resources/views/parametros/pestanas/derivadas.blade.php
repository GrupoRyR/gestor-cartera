@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The «Comisiones derivadas» tab: one row per derived commission (81.1, 85.1). $kind, $entries, $canAdd. --}}
@php
    $columns = [];
    foreach (['clave', 'etiqueta', 'persona', 'codigo_origen', 'base', 'tasa', 'vigencia', 'acciones'] as $field) {
        $columns[] = [
            'key' => $field,
            'label' => __('comisiones::parametros.campos.'.$field),
            'align' => $field === 'tasa' ? 'end' : 'start',
        ];
    }
@endphp

<x-ui.card :title="__('comisiones::parametros.derivadas.titulo')" :description="__('comisiones::parametros.derivadas.descripcion')">
    <x-ui.table :caption="__('comisiones::parametros.derivadas.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::parametros.lista.vacio', ['fecha' => $dayLabel])">
        @foreach ($entries as $entry)
            @php($data = $entry->shown()->datos)
            <tr data-key="{{ $entry->key }}">
                <td data-col="clave" class="font-semibold">{{ $entry->key }}</td>
                <td data-col="etiqueta" class="min-w-48">{{ ParameterValues::text($data['etiqueta'] ?? null) }}</td>
                <td data-col="persona" class="min-w-40">{{ ParameterValues::person($data['persona'] ?? null) ?? __('comisiones::comun.sin_dato') }}</td>
                <td data-col="codigo_origen">{{ ParameterValues::text($data['codigo_origen'] ?? null) }}</td>
                <td data-col="base">{{ ParameterValues::base($data['base'] ?? null) }}</td>
                <td data-col="tasa" data-align="end" class="whitespace-nowrap">{{ ParameterValues::percent($data['tasa'] ?? null) }}</td>
                <td data-col="vigencia">@include('comisiones::parametros.vigencia', ['entry' => $entry])</td>
                <td data-col="acciones">@include('comisiones::parametros.acciones', ['kind' => $kind, 'key' => $entry->key, 'of' => $kind->of($entry->key), 'canAdd' => $canAdd])</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
