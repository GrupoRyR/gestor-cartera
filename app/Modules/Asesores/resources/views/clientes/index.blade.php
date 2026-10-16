@use('App\Modules\Core\Contracts\Enums\ClientStatus')
@use('App\Support\Formatting\Money')

{{--
    GET /asesores/clientes (asesores.clientes.index): the clients the user
    may see, with a search, a status, "only overdue" and an advisor filter, a
    table sortable by the columns CarteraRepository sorts by, 25 per page.
    $clients is the page, $totals the figures of the same filter (so the
    summary line matches the table), $advisors the people the user can look
    at, and $showAdvisor says whether the advisor column adds anything.
--}}
@php
    $filtered = $filter->params(['sort' => null, 'direction' => null]) !== [];

    $columns = array_values(array_filter([
        ['key' => 'name', 'label' => __('asesores::clientes.columnas.cliente'), 'sortable' => true],
        ['key' => 'status', 'label' => __('asesores::clientes.columnas.estado'), 'sortable' => true],
        $showAdvisor ? ['key' => 'advisor', 'label' => __('asesores::clientes.columnas.asesor')] : null,
        ['key' => 'overdue', 'label' => __('asesores::clientes.columnas.vencido'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'days_overdue', 'label' => __('asesores::clientes.columnas.dias'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'balance', 'label' => __('asesores::clientes.columnas.saldo'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'last_activity', 'label' => __('asesores::clientes.columnas.gestion'), 'sortable' => true],
    ]));
@endphp

<x-layouts.app :title="__('asesores::clientes.titulo')">
    <x-ui.page-header :title="__('asesores::clientes.titulo')" :description="__('asesores::clientes.descripcion')">
        <x-slot:actions>
            {{-- The file holds the clients of the list: the same filters and order. --}}
            <x-ui.link-button :href="route('asesores.clientes.exportar', $filter->params())" variant="secondary" icon="download">{{ __('asesores::exportar.boton.excel') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-6 grid gap-6">
        <x-asesores::advisor-filter :advisors="$advisors" :filter="$filter" :action="route('asesores.clientes.index')" />

        <x-asesores::cutoff :cutoff="$totals->cutoffDate" :today="$today" />

        <form method="GET" action="{{ route('asesores.clientes.index') }}" role="search" data-part="client-filters" class="grid gap-4 rounded-card border border-line bg-surface p-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] md:items-end">
            @foreach ($filter->params(['buscar' => null, 'estado' => null, 'vencidos' => null]) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <x-ui.input
                name="buscar"
                type="search"
                :label="__('asesores::clientes.buscar.etiqueta')"
                :hint="__('asesores::clientes.buscar.ayuda')"
                :value="$filter->search"
                enterkeyhint="search"
            />
            <x-ui.select
                name="estado"
                :label="__('asesores::clientes.filtros.estado')"
                :options="ClientStatus::options()"
                :value="$filter->status"
                :placeholder="__('asesores::clientes.filtros.todos_estados')"
            />
            <x-ui.checkbox name="vencidos" value="1" :checked="$filter->onlyOverdue" :label="__('asesores::clientes.filtros.vencidos')" />

            <div class="flex flex-wrap items-center gap-3 md:justify-end">
                <x-ui.button type="submit" icon="search">{{ __('asesores::clientes.buscar.boton') }}</x-ui.button>
                @if ($filtered)
                    <x-ui.link-button :href="route('asesores.clientes.index', $filter->params(['buscar' => null, 'estado' => null, 'vencidos' => null]))" variant="ghost">{{ __('asesores::clientes.filtros.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        @if ($clients->total() === 0)
            <x-ui.empty
                icon="users"
                :title="__($filtered ? 'asesores::clientes.vacio.filtrado_titulo' : 'asesores::clientes.vacio.titulo')"
                :description="__($filtered ? 'asesores::clientes.vacio.filtrado_detalle' : 'asesores::clientes.vacio.detalle')"
            />
        @else
            <p data-part="summary" class="text-sm text-fg-soft" aria-live="polite">
                {{ trans_choice('asesores::clientes.resumen', $totals->clientCount, ['clientes' => $totals->clientCount, 'saldo' => Money::format($totals->total), 'vencido' => Money::format($totals->overdue)]) }}
            </p>

            <x-ui.table
                :caption="__('asesores::clientes.tabla')"
                :caption-hidden="true"
                :columns="$columns"
                :sort-by="$filter->sort"
                :sort-direction="$filter->direction"
            >
                @foreach ($clients as $client)
                    <tr>
                        <td>
                            <a href="{{ route('asesores.clientes.show', $client->id) }}" class="block min-h-touch font-semibold text-brand-ink underline-offset-4 hover:underline [overflow-wrap:anywhere]">{{ $client->name }}</a>
                            <span class="block text-xs text-fg-muted">{{ __('asesores::clientes.cliente_detalle', ['codigo' => $client->code, 'nit' => $client->taxId]) }}</span>
                        </td>
                        <td><x-ui.badge-state :state="$client->status" /></td>
                        @if ($showAdvisor)
                            <td>{{ $client->advisor?->name ?? __('asesores::comun.sin_datos') }}</td>
                        @endif
                        <td data-align="end"><x-ui.money :value="$client->overdue > 0 ? $client->overdue : null" :fallback="__('asesores::comun.sin_datos')" /></td>
                        <td data-align="end">{{ $client->maxDaysOverdue > 0 ? __('asesores::clientes.dias', ['dias' => $client->maxDaysOverdue]) : __('asesores::comun.sin_datos') }}</td>
                        <td data-align="end"><x-ui.money :value="$client->balance" /></td>
                        <td>
                            @if ($client->lastActivityAt !== null)
                                <x-ui.date :value="$client->lastActivityAt" />
                            @elseif ($client->overdue > 0)
                                <x-ui.chip tone="warning">{{ __('asesores::comun.sin_gestion') }}</x-ui.chip>
                            @else
                                {{ __('asesores::comun.sin_datos') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-ui.pagination :paginator="$clients" />
        @endif
    </div>
</x-layouts.app>
