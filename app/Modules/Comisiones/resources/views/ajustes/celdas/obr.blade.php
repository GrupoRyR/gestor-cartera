@use('App\Modules\Comisiones\Domain\Shared\Percentage')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')

{{-- The detail cells of an OBR/Escudos sale. $capture: ObrRecord. --}}
<td class="font-semibold">{{ $capture->slp_code }}</td>
<td class="min-w-48 [overflow-wrap:anywhere]">{{ $capture->descripcion }}</td>
<td data-align="end"><x-ui.money :value="$capture->valor_vendido" /></td>
<td data-align="end">{{ Labels::rate(Percentage::of($capture->tasa)) }}</td>
