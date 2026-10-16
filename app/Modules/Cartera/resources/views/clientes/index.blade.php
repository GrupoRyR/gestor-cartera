@use('App\Modules\Cartera\Application\Clients\ClientListFilter')

{{--
    GET /cartera/clientes (cartera.clientes.index): the client list.
    $filter: Application\Clients\ClientListFilter; $clients: paginator of
    ClientSummary within the user's scope; $statusOptions and
    $advisorOptions: value => label for the filters; $isGroup: the group view
    shows the company of each client.
--}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('cartera::clientes.columnas.cliente'), 'sortable' => true],
        ...($isGroup ? [['key' => 'company', 'label' => __('cartera::clientes.columnas.empresa')]] : []),
        ['key' => 'tax_id', 'label' => __('cartera::clientes.columnas.nit')],
        ['key' => 'balance', 'label' => __('cartera::clientes.columnas.total'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'overdue', 'label' => __('cartera::clientes.columnas.vencido'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'days_overdue', 'label' => __('cartera::clientes.columnas.dias'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'status', 'label' => __('cartera::clientes.columnas.estado'), 'sortable' => true],
        ['key' => 'advisor', 'label' => __('cartera::clientes.columnas.asesor')],
        ['key' => 'manager', 'label' => __('cartera::clientes.columnas.gestor')],
        ['key' => 'last_activity', 'label' => __('cartera::clientes.columnas.ultima_gestion'), 'sortable' => true],
    ];
@endphp

<x-layouts.app :title="__('cartera::clientes.titulo')">
    <x-ui.page-header :title="__('cartera::clientes.titulo')" :description="__('cartera::clientes.descripcion')" />

    <form method="GET" action="{{ route('cartera.clientes.index') }}" role="search" aria-label="{{ __('cartera::clientes.filtros.etiqueta') }}" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 md:grid-cols-2 md:p-5 lg:grid-cols-4">
        @if ($filter->sort !== ClientListFilter::DEFAULT_SORT || $filter->direction !== ClientListFilter::DEFAULT_DIRECTION)
            <input type="hidden" name="sort" value="{{ $filter->sort }}">
            <input type="hidden" name="direction" value="{{ $filter->direction }}">
        @endif

        <x-ui.input
            name="buscar"
            type="search"
            :label="__('cartera::clientes.filtros.buscar')"
            :value="$filter->search"
            :placeholder="__('cartera::clientes.filtros.buscar_ayuda')"
        />
        <x-ui.select
            name="estado"
            :label="__('cartera::clientes.filtros.estado')"
            :options="$statusOptions"
            :value="$filter->status?->value"
            :placeholder="__('cartera::clientes.filtros.todos_estados')"
        />
        <x-ui.select
            name="asesor"
            :label="__('cartera::clientes.filtros.asesor')"
            :options="$advisorOptions"
            :value="$filter->advisorCode"
            :placeholder="__('cartera::clientes.filtros.todos_asesores')"
        />

        <fieldset class="grid content-start gap-2">
            <legend class="sr-only">{{ __('cartera::clientes.filtros.etiqueta') }}</legend>
            <x-ui.checkbox name="vencidos" :label="__('cartera::clientes.filtros.vencidos')" :checked="$filter->onlyOverdue" />
            <x-ui.checkbox name="sin_gestion" :label="__('cartera::clientes.filtros.sin_gestion', ['dias' => $unmanagedDays])" :checked="$filter->unmanaged" />
        </fieldset>

        <div class="flex flex-wrap items-center gap-2 md:col-span-2 lg:col-span-4">
            <x-ui.button type="submit" icon="search">{{ __('cartera::clientes.filtros.aplicar') }}</x-ui.button>
            @if ($filter->isFiltered())
                <x-ui.link-button :href="route('cartera.clientes.index')" variant="ghost">{{ __('cartera::clientes.filtros.limpiar') }}</x-ui.link-button>
            @endif
        </div>
    </form>

    @if ($clients->isEmpty())
        @if ($filter->isFiltered())
            <x-ui.empty icon="search" :title="__('cartera::clientes.vacio.sin_resultados.titulo')" :description="__('cartera::clientes.vacio.sin_resultados.detalle')">
                <x-ui.link-button :href="route('cartera.clientes.index')" variant="secondary">{{ __('cartera::clientes.vacio.sin_resultados.accion') }}</x-ui.link-button>
            </x-ui.empty>
        @else
            <x-ui.empty icon="users" :title="__('cartera::clientes.vacio.sin_clientes.titulo')" :description="__('cartera::clientes.vacio.sin_clientes.detalle')" />
        @endif
    @else
        <x-ui.table
            :caption="__('cartera::clientes.tabla')"
            :caption-hidden="true"
            :columns="$columns"
            :sort-by="$filter->sort"
            :sort-direction="$filter->direction"
        >
            @foreach ($clients as $client)
                <tr>
                    <td>{{ $client->name }}</td>
                    @if ($isGroup)
                        <td><x-ui.chip :dot="false" :data-company="$client->company->value">{{ $client->company->value }}</x-ui.chip></td>
                    @endif
                    <td>{{ $client->taxId }}</td>
                    <td data-align="end"><x-ui.money :value="$client->balance" /></td>
                    <td data-align="end"><x-ui.money :value="$client->overdue" /></td>
                    <td data-align="end">{{ $client->maxDaysOverdue > 0 ? number_format($client->maxDaysOverdue, 0, ',', '.') : '—' }}</td>
                    <td><x-ui.badge-state :state="$client->status" /></td>
                    <td>{{ $client->advisor?->name ?? __('cartera::clientes.sin_asesor') }}</td>
                    <td>{{ $client->manager?->name ?? __('cartera::clientes.sin_gestor') }}</td>
                    <td>
                        @if ($client->lastActivityAt !== null)
                            <x-ui.date :value="$client->lastActivityAt" />
                        @elseif ($client->overdue > 0)
                            <x-ui.chip tone="warning">{{ __('cartera::clientes.sin_gestion') }}</x-ui.chip>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$clients" />
    @endif
</x-layouts.app>
