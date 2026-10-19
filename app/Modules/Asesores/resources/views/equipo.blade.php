@use('App\Modules\Asesores\Domain\Format\Percent')
@use('App\Modules\Asesores\Domain\Portfolio\AdvisorKey')
@use('App\Support\Formatting\Money')

{{--
    GET /asesores/equipo (asesores.equipo.index): the advisors of a sales
    manager, the most overdue first, each with the clients, balance, overdue
    (a bar against the biggest of the team, the amount and its share of the
    advisor's own balance), what is over 90 days and the commitments that
    were not paid. $team comes from Core's list of advisors the manager can
    see, so it never lists somebody else's.
--}}
@php
    $columns = [
        ['key' => 'asesor', 'label' => __('asesores::equipo.columnas.asesor')],
        ['key' => 'clientes', 'label' => __('asesores::equipo.columnas.clientes'), 'align' => 'end'],
        ['key' => 'saldo', 'label' => __('asesores::equipo.columnas.saldo'), 'align' => 'end'],
        ['key' => 'vencido', 'label' => __('asesores::equipo.columnas.vencido')],
        ['key' => 'mas_90', 'label' => __('asesores::equipo.columnas.mas_90'), 'align' => 'end'],
        ['key' => 'incumplidos', 'label' => __('asesores::equipo.columnas.incumplidos'), 'align' => 'end'],
        ['key' => 'acciones', 'label' => __('asesores::equipo.columnas.acciones'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('asesores::equipo.titulo')">
    <x-ui.page-header :title="__('asesores::equipo.titulo')" :description="__('asesores::equipo.descripcion')" />

    <div class="mt-6 grid gap-6">
        <x-asesores::cutoff :cutoff="$team->cutoff" :today="$today" />

        @if ($team->rows === [])
            <x-ui.empty icon="users" :title="__('asesores::equipo.vacio.titulo')" :description="__('asesores::equipo.vacio.detalle')" />
        @else
            <p data-part="summary" class="text-sm text-fg-soft">
                {{ trans_choice('asesores::equipo.resumen', count($team->rows), ['asesores' => count($team->rows), 'vencido' => Money::format($team->overdue)]) }}
            </p>

            <x-ui.table :caption="__('asesores::equipo.tabla')" :caption-hidden="true" :columns="$columns">
                @foreach ($team->rows as $row)
                    @php($advisor = $row->advisor)
                    <tr>
                        <td>
                            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $advisor->name }}</span>
                            <span class="block text-xs text-fg-muted">
                                {{ AdvisorKey::of($advisor)->value() }}@unless ($advisor->active) · {{ __('asesores::equipo.inactivo') }}@endunless
                            </span>
                        </td>
                        <td data-align="end">{{ $row->totals->clientCount }}</td>
                        <td data-align="end"><x-ui.money :value="$row->totals->total" /></td>
                        <td>
                            <span class="flex items-center gap-3">
                                <span aria-hidden="true" class="h-3 w-20 shrink-0 overflow-hidden rounded-full bg-subtle">
                                    <span class="block h-full rounded-full bg-brand-primary" style="width: {{ $row->width }}%"></span>
                                </span>
                                <span class="tabular-nums">
                                    <x-ui.money :value="$row->totals->overdue" class="font-semibold" />
                                    <span class="text-fg-muted">· {{ Percent::format($row->totals->overduePercent(), 0) }}</span>
                                </span>
                            </span>
                        </td>
                        <td data-align="end"><x-ui.money :value="$row->totals->aging->over90 > 0 ? $row->totals->aging->over90 : null" :fallback="__('asesores::comun.sin_datos')" /></td>
                        <td data-align="end">{{ $row->broken > 0 ? $row->broken : __('asesores::comun.sin_datos') }}</td>
                        <td data-align="end">
                            <x-ui.link-button :href="route('asesores.inicio', ['asesor' => AdvisorKey::of($advisor)->value()])" variant="ghost">
                                {{ __('asesores::equipo.ver') }}<span data-visually-hidden>&nbsp;{{ $advisor->name }}</span>
                            </x-ui.link-button>
                        </td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <tr>
                        <th scope="row">{{ __('asesores::equipo.total') }}</th>
                        <td data-align="end">{{ $team->clients }}</td>
                        <td data-align="end"><x-ui.money :value="$team->total" /></td>
                        <td><x-ui.money :value="$team->overdue" class="font-semibold" /></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>

            <p class="text-sm text-fg-muted">{{ __('asesores::equipo.nota') }}</p>
        @endif
    </div>
</x-layouts.app>
