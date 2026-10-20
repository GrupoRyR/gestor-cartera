{{--
    The detail cells of a reassignment. $capture: ReassignmentRecord;
    $crossings: clave => CrossingRecord of the crossings the rows name.
--}}
@php
    $crossing = $crossings[$capture->clave_cruce] ?? null;
@endphp

<td class="min-w-56 [overflow-wrap:anywhere]">
    @if ($crossing === null)
        {{ __('comisiones::ajustes.reasignaciones.cruce_no_existe') }}
    @else
        {{ __('comisiones::ajustes.reasignaciones.opcion', [
            'factura' => $crossing->numero_factura ?? (string) ($crossing->doc_entry_factura ?? '—'),
            'cliente' => $crossing->razon_social ?? $crossing->codigo_cliente,
            'fecha' => $crossing->fecha_cruce->format('d/m/Y'),
            'vendedor' => $crossing->slp_code_capturado ?? $crossing->slp_code_factura ?? __('comisiones::ajustes.reasignaciones.sin_vendedor'),
        ]) }}
    @endif
</td>
<td class="font-semibold">{{ $capture->slp_code }}</td>
<td class="min-w-48 [overflow-wrap:anywhere]">{{ $capture->motivo }}</td>
