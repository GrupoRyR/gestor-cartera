{{-- The detail cells of a manual crossing. $capture: CrossingRecord (origen manual). --}}
<td class="font-semibold">{{ $capture->slp_code_capturado ?? __('comisiones::comun.sin_dato') }}</td>
<td>{{ __('comisiones::ajustes.cruces.tipos.'.$capture->tipo_cruce) }}</td>
<td>{{ $capture->numero_factura ?? __('comisiones::comun.sin_dato') }}</td>
<td class="min-w-48 [overflow-wrap:anywhere]">
    {{ $capture->razon_social ?? __('comisiones::comun.sin_dato') }}
    <span class="block text-sm text-fg-muted">{{ $capture->codigo_cliente }}</span>
</td>
<td><x-ui.date :value="$capture->fecha_cruce->format('Y-m-d')" /></td>
<td data-align="end"><x-ui.money :value="$capture->valor_compensado" /></td>
