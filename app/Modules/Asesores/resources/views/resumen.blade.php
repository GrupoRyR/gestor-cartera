@use('App\Modules\Asesores\Application\Portfolio\PortfolioOverview')
@use('App\Modules\Asesores\Domain\Format\Percent')
@use('App\Support\Formatting\Money')

{{--
    GET /asesores (asesores.inicio): the dashboard. Totals, age chart,
    priority clients and the trend of the overdue balance, all for the
    portfolio the user may see; a sales manager (or an advisor with several
    SAP codes) can narrow it to one advisor. $overview holds what the contract
    returned, $filter the query string, $today the current date in Bogotá.
--}}
@php
    $totals = $overview->totals;
    $heading = match (true) {
        $overview->selected !== null => __('asesores::resumen.titulo_asesor', ['nombre' => $overview->selected->name]),
        $isManager => __('asesores::resumen.titulo_equipo'),
        default => __('asesores::resumen.titulo'),
    };
    $notDuePercent = $totals->total > 0 ? 100 - $totals->overduePercent() : 0;
@endphp

<x-layouts.app :title="$heading">
    <x-ui.page-header :title="$heading" :description="__('asesores::resumen.descripcion')" />

    <div class="mt-6 grid gap-6">
        @if ($isManager && $overview->selected !== null)
            <x-ui.alert tone="info">
                {{ __('asesores::equipo.viendo', ['nombre' => $overview->selected->name]) }}
                <x-ui.link-button :href="route('asesores.equipo.index')" variant="ghost" icon="chevron-left">{{ __('asesores::equipo.volver') }}</x-ui.link-button>
            </x-ui.alert>
        @endif

        <x-asesores::advisor-filter :advisors="$overview->advisors" :filter="$filter" :action="route('asesores.inicio')" />

        <x-asesores::cutoff :cutoff="$totals->cutoffDate" :today="$today" />

        @if ($totals->cutoffDate === null)
            <x-ui.empty icon="document" :title="__('asesores::resumen.vacio.titulo')" :description="__('asesores::resumen.vacio.detalle')" />
        @else
            <section aria-label="{{ __('asesores::resumen.titulo') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-ui.stat :label="__('asesores::resumen.kpis.saldo')" :value="Money::format($totals->total)" />
                <x-ui.stat
                    :label="__('asesores::resumen.kpis.sin_vencer')"
                    :value="Money::format($totals->aging->notDue)"
                    :hint="__('asesores::resumen.kpis.del_total', ['porcentaje' => Percent::format($notDuePercent)])"
                    tone="success"
                />
                <x-ui.stat
                    :label="__('asesores::resumen.kpis.vencido')"
                    :value="Money::format($totals->overdue)"
                    :hint="__('asesores::resumen.kpis.del_total', ['porcentaje' => Percent::format($totals->overduePercent())])"
                    :tone="$totals->overdue > 0 ? 'warning' : 'neutral'"
                />
                <x-ui.stat
                    :label="__('asesores::resumen.kpis.mas_90')"
                    :value="Money::format($totals->aging->over90)"
                    :hint="__('asesores::resumen.kpis.dificil_cobro')"
                    :tone="$totals->aging->over90 > 0 ? 'danger' : 'neutral'"
                />
                <x-ui.stat :label="__('asesores::resumen.kpis.clientes')" :value="(string) $totals->clientCount" />
                <x-ui.stat
                    :label="__('asesores::resumen.kpis.clientes_vencido')"
                    :value="(string) $totals->overdueClientCount"
                    :hint="__('asesores::resumen.kpis.de_clientes', ['total' => $totals->clientCount])"
                    :href="route('asesores.clientes.index', $filter->params(['vencidos' => '1']))"
                />
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-ui.card :title="__('asesores::resumen.edades.titulo')" :description="__('asesores::resumen.edades.ayuda')">
                    <x-asesores::aging-bars :aging="$totals->aging" />
                </x-ui.card>

                <x-ui.card :title="__('asesores::resumen.prioridad.titulo')" :description="__('asesores::resumen.prioridad.ayuda', ['dias' => PortfolioOverview::PRIORITY_DAYS])">
                    @if ($overview->priority === [])
                        <p class="text-sm text-fg-soft">{{ __('asesores::resumen.prioridad.vacio', ['dias' => PortfolioOverview::PRIORITY_DAYS]) }}</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($overview->priority as $client)
                                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3 first:pt-0">
                                    <span class="min-w-0">
                                        <a href="{{ route('asesores.clientes.show', $client->id) }}" class="block min-h-touch font-semibold text-brand-ink underline-offset-4 hover:underline [overflow-wrap:anywhere]">{{ $client->name }}</a>
                                        <span class="block text-sm text-fg-muted">{{ __('asesores::resumen.prioridad.dias', ['dias' => $client->maxDaysOverdue]) }}</span>
                                    </span>
                                    <x-ui.money :value="$client->overdue" class="font-semibold" />
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <x-slot:footer>
                        <x-ui.link-button :href="route('asesores.clientes.index', $filter->params())" variant="ghost" icon="chevron-right">{{ __('asesores::resumen.prioridad.ver_todos') }}</x-ui.link-button>
                    </x-slot:footer>
                </x-ui.card>
            </div>

            <x-ui.card :title="__('asesores::resumen.tendencia.titulo')" :description="__('asesores::resumen.tendencia.ayuda', ['dias' => config('asesores.history.dashboard_days')])">
                <x-asesores::trend-chart :history="$overview->history" :table="false" />

                <x-slot:footer>
                    <x-ui.link-button :href="route('asesores.historico', $filter->params())" variant="ghost" icon="chevron-right">{{ __('asesores::resumen.tendencia.ver_historico') }}</x-ui.link-button>
                </x-slot:footer>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
