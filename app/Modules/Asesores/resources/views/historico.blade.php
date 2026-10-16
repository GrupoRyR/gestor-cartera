@use('App\Modules\Asesores\Domain\Format\Percent')

{{--
    GET /asesores/historico (asesores.historico): one snapshot of the
    portfolio per cutoff over the chosen period, as the trend chart and a
    table. $history is PortfolioTotals oldest first; $days the period; the
    advisor filter works as on the dashboard.
--}}
@php
    $tabs = array_map(fn (int $range): array => [
        'key' => (string) $range,
        'label' => __('asesores::historico.rangos.dias', ['dias' => $range]),
        'href' => route('asesores.historico', [...$filter->params(), 'dias' => $range]),
    ], config('asesores.history.ranges'));

    $columns = [
        ['key' => 'fecha', 'label' => __('asesores::historico.tabla.columnas.fecha')],
        ['key' => 'saldo', 'label' => __('asesores::historico.tabla.columnas.saldo'), 'align' => 'end'],
        ['key' => 'vencido', 'label' => __('asesores::historico.tabla.columnas.vencido'), 'align' => 'end'],
        ['key' => 'porcentaje', 'label' => __('asesores::historico.tabla.columnas.porcentaje'), 'align' => 'end'],
        ['key' => 'mas_90', 'label' => __('asesores::historico.tabla.columnas.mas_90'), 'align' => 'end'],
        ['key' => 'clientes', 'label' => __('asesores::historico.tabla.columnas.clientes'), 'align' => 'end'],
    ];

    $latest = $history === [] ? null : $history[array_key_last($history)]->cutoffDate;
@endphp

<x-layouts.app :title="__('asesores::historico.titulo')">
    <x-ui.page-header :title="__('asesores::historico.titulo')" :description="__('asesores::historico.descripcion')" />

    <div class="mt-6 grid gap-6">
        <x-asesores::advisor-filter :advisors="$advisors" :filter="$filter" :action="route('asesores.historico')" :extra="['dias' => $days]" />

        <x-ui.tabs :label="__('asesores::historico.rangos.etiqueta')" :items="$tabs" :current="(string) $days" />

        <x-asesores::cutoff :cutoff="$latest" :today="$today" />

        @if ($history === [])
            <x-ui.empty icon="history" :title="__('asesores::historico.vacio.titulo')" :description="__('asesores::historico.vacio.detalle')" />
        @else
            <x-ui.card :title="__('asesores::historico.grafico.titulo')">
                <x-asesores::trend-chart :history="$history" :table="false" />
            </x-ui.card>

            <x-ui.table :caption="__('asesores::historico.tabla.titulo')" :columns="$columns">
                @foreach (array_reverse($history) as $totals)
                    <tr>
                        <td><x-ui.date :value="$totals->cutoffDate" /></td>
                        <td data-align="end"><x-ui.money :value="$totals->total" /></td>
                        <td data-align="end"><x-ui.money :value="$totals->overdue" /></td>
                        <td data-align="end">{{ Percent::format($totals->overduePercent()) }}</td>
                        <td data-align="end"><x-ui.money :value="$totals->aging->over90" /></td>
                        <td data-align="end">{{ $totals->clientCount }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </div>
</x-layouts.app>
