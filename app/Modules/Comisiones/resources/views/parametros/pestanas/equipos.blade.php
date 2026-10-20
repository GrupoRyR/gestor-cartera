@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The «Equipos de directores» tab: one row per team. $kind, $entries (list<ParameterEntry>), $canAdd. --}}
@php
    $columns = [];
    foreach (['clave', 'canal', 'director', 'codigo_asociado', 'tasa', 'miembros', 'vigencia', 'acciones'] as $field) {
        $columns[] = [
            'key' => $field,
            'label' => __('comisiones::parametros.campos.'.$field),
            'align' => $field === 'tasa' ? 'end' : 'start',
        ];
    }
@endphp

<x-ui.card :title="__('comisiones::parametros.equipos.titulo')" :description="__('comisiones::parametros.equipos.descripcion')">
    <x-ui.table :caption="__('comisiones::parametros.equipos.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::parametros.lista.vacio', ['fecha' => $dayLabel])">
        @foreach ($entries as $entry)
            @php($data = $entry->shown()->datos)
            <tr data-key="{{ $entry->key }}">
                <td data-col="clave" class="font-semibold">{{ $entry->key }}</td>
                <td data-col="canal" class="min-w-40">{{ ParameterValues::text($data['canal'] ?? null) }}</td>
                <td data-col="director" class="min-w-40">{{ ParameterValues::person($data['director'] ?? null) ?? __('comisiones::comun.sin_dato') }}</td>
                <td data-col="codigo_asociado">{{ ($data['codigo_asociado'] ?? null) === null ? __('comisiones::parametros.valores.ninguno') : ParameterValues::text($data['codigo_asociado']) }}</td>
                <td data-col="tasa" data-align="end" class="whitespace-nowrap">{{ ParameterValues::percent($data['tasa'] ?? null) }}</td>
                <td data-col="miembros" class="min-w-48">{{ ParameterValues::members($data['miembros'] ?? null) }}</td>
                <td data-col="vigencia">@include('comisiones::parametros.vigencia', ['entry' => $entry])</td>
                <td data-col="acciones">@include('comisiones::parametros.acciones', ['kind' => $kind, 'key' => $entry->key, 'of' => $kind->of($entry->key, $entry->shown()), 'canAdd' => $canAdd])</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
