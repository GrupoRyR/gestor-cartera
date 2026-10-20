@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The «Códigos» tab: one row per seller code. $kind, $entries (list<ParameterEntry>), $day, $canAdd. --}}
@php
    $columns = [];
    foreach (['codigo', 'nombre', 'persona', 'tasa_venta', 'tasa_recaudo', 'cumplimiento_minimo', 'alcance', 'usa_tramos', 'vigencia', 'acciones'] as $field) {
        $columns[] = [
            'key' => $field,
            'label' => __('comisiones::parametros.campos.'.$field),
            'align' => in_array($field, ['tasa_venta', 'tasa_recaudo', 'cumplimiento_minimo'], true) ? 'end' : 'start',
        ];
    }
@endphp

<x-ui.card :title="__('comisiones::parametros.codigos.titulo')" :description="__('comisiones::parametros.codigos.descripcion')">
    <x-ui.table :caption="__('comisiones::parametros.codigos.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::parametros.lista.vacio', ['fecha' => $dayLabel])">
        @foreach ($entries as $entry)
            @php
                $data = $entry->shown()->datos;
                $name = ParameterValues::text($data['nombre'] ?? null);
            @endphp
            <tr data-key="{{ $entry->key }}">
                <td data-col="codigo" class="font-semibold">{{ $entry->key }}</td>
                <td data-col="nombre" class="min-w-40">{{ $name }}</td>
                <td data-col="persona" class="min-w-40">{{ ParameterValues::person($data['persona'] ?? null) ?? $name }}</td>
                <td data-col="tasa_venta" data-align="end" class="whitespace-nowrap">{{ ParameterValues::percent($data['tasa_venta'] ?? null) }}</td>
                <td data-col="tasa_recaudo" data-align="end" class="whitespace-nowrap">{{ ParameterValues::percent($data['tasa_recaudo'] ?? null) }}</td>
                <td data-col="cumplimiento_minimo" data-align="end" class="whitespace-nowrap">{{ ParameterValues::percent($data['cumplimiento_minimo'] ?? null) }}</td>
                <td data-col="alcance">{{ ParameterValues::scope($data['alcance'] ?? null) }}</td>
                <td data-col="tramos" class="whitespace-nowrap">{{ ParameterValues::tranches($data['usa_tramos'] ?? null) }}</td>
                <td data-col="vigencia">@include('comisiones::parametros.vigencia', ['entry' => $entry])</td>
                <td data-col="acciones">@include('comisiones::parametros.acciones', ['kind' => $kind, 'key' => $entry->key, 'of' => $kind->of($entry->key), 'canAdd' => $canAdd])</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
