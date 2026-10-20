@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{-- The «Tramos de recaudo» tab: the rows of the tranche table in force. $kind, $entries (at most one), $canAdd. --}}
@php
    $entry = $entries[0] ?? null;
    $tranches = $entry === null ? [] : (array) ($entry->shown()->datos['tramos'] ?? []);
    $columns = [
        ['key' => 'desde', 'label' => __('comisiones::parametros.campos.desde'), 'align' => 'end'],
        ['key' => 'hasta', 'label' => __('comisiones::parametros.campos.hasta'), 'align' => 'end'],
        ['key' => 'participacion', 'label' => __('comisiones::parametros.campos.participacion'), 'align' => 'end'],
    ];

    $validity = null;
    if ($entry?->current !== null) {
        $validity = __('comisiones::parametros.tramos.vigente', ['fecha' => ParameterValues::day($entry->current->vigente_desde)]);
        if ($entry->next !== null) {
            $validity .= ' '.__('comisiones::parametros.tramos.cambia', ['fecha' => ParameterValues::day($entry->next->vigente_desde)]);
        }
    } elseif ($entry?->next !== null) {
        $validity = __('comisiones::parametros.tramos.empieza', ['fecha' => ParameterValues::day($entry->next->vigente_desde)]);
    }
@endphp

<x-ui.card :title="__('comisiones::parametros.tramos.titulo')" :description="trim(__('comisiones::parametros.tramos.descripcion').' '.$validity)">
    <x-slot:actions>
        @include('comisiones::parametros.acciones', ['kind' => $kind, 'key' => $kind->value, 'of' => $kind->of($kind->value), 'canAdd' => $canAdd])
    </x-slot:actions>

    <x-ui.table :caption="__('comisiones::parametros.tramos.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::parametros.lista.vacio', ['fecha' => $dayLabel])">
        @foreach ($tranches as $tranche)
            <tr data-key="{{ ParameterValues::text($tranche['desde'] ?? null) }}">
                <td data-col="desde" data-align="end">{{ ParameterValues::text($tranche['desde'] ?? null) }}</td>
                <td data-col="hasta" data-align="end">{{ ParameterValues::until($tranche['hasta'] ?? null) }}</td>
                <td data-col="participacion" data-align="end">{{ ParameterValues::percent($tranche['participacion'] ?? null) }}</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
