@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{--
    The rows of a tranche table version (§5.3): the rows to start from plus
    EXTRA empty ones to add tranches without JavaScript. After a failed
    submit the rows typed come back by their index (old input wins in every
    field). $values: the stored "datos" ({tramos: [{desde, hasta, participacion}]}).
--}}
@php
    $extra = 3;
    $rows = array_values(array_filter((array) ($values['tramos'] ?? []), 'is_array'));
    $old = old('tramos');
    $count = count($rows) + $extra;
    if (is_array($old) && $old !== []) {
        $count = max($count, max(array_map('intval', array_keys($old))) + 1);
    }
    $columns = [
        ['key' => 'desde', 'label' => __('comisiones::parametros.campos.desde')],
        ['key' => 'hasta', 'label' => __('comisiones::parametros.campos.hasta')],
        ['key' => 'participacion', 'label' => __('comisiones::parametros.campos.participacion')],
    ];
    $tableError = $errors->first('tramos');
@endphp

<p class="text-sm text-fg-soft">{{ __('comisiones::parametros.formulario.tramos.ayuda') }}</p>

@if ($tableError !== '')
    <x-ui.alert tone="danger">{{ $tableError }}</x-ui.alert>
@endif

<x-ui.table :caption="__('comisiones::parametros.formulario.tramos.caption')" :caption-hidden="true" :columns="$columns">
    @for ($i = 0; $i < $count; $i++)
        @php($row = $rows[$i] ?? [])
        <tr data-row="{{ $i }}">
            <td class="min-w-32">
                <x-ui.input
                    name="tramos[{{ $i }}][desde]"
                    :label="__('comisiones::parametros.formulario.tramos.fila', ['campo' => __('comisiones::parametros.campos.desde'), 'fila' => $i + 1])"
                    :label-hidden="true"
                    :value="isset($row['desde']) ? (string) $row['desde'] : null"
                    inputmode="numeric"
                    autocomplete="off"
                />
            </td>
            <td class="min-w-32">
                <x-ui.input
                    name="tramos[{{ $i }}][hasta]"
                    :label="__('comisiones::parametros.formulario.tramos.fila', ['campo' => __('comisiones::parametros.campos.hasta'), 'fila' => $i + 1])"
                    :label-hidden="true"
                    :value="isset($row['hasta']) ? (string) $row['hasta'] : null"
                    inputmode="numeric"
                    autocomplete="off"
                />
            </td>
            <td class="min-w-32">
                <x-ui.input
                    name="tramos[{{ $i }}][participacion]"
                    :label="__('comisiones::parametros.formulario.tramos.fila', ['campo' => __('comisiones::parametros.campos.participacion'), 'fila' => $i + 1])"
                    :label-hidden="true"
                    :value="isset($row['participacion']) ? ParameterValues::percentInput($row['participacion']) : null"
                    suffix="%"
                    inputmode="decimal"
                    autocomplete="off"
                />
            </td>
        </tr>
    @endfor
</x-ui.table>
