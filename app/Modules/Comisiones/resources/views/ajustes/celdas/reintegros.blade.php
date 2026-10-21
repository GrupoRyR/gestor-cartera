{{-- The detail cells of a reimbursement or deduction. $capture: AdjustmentRecord. --}}
<td class="font-semibold">{{ $capture->slp_code }}</td>
<td>{{ __('comisiones::ajustes.reintegros.tipos.'.$capture->tipo) }}</td>
<td data-align="end"><x-ui.money :value="$capture->valor" /></td>
<td class="min-w-48 [overflow-wrap:anywhere]">{{ $capture->descripcion }}</td>
