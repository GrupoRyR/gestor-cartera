@use('App\Modules\Cartera\Application\Clients\ClientListFilter')

{{--
    GET /cartera/clientes (cartera.clientes.index): the client list.
    $filter: Application\Clients\ClientListFilter; $clients: paginator of
    ClientSummary within the user's scope; $statusOptions, $advisorOptions
    and $managerOptions: value => label for the filters; $isGroup: the group view
    shows the company of each client; $assigneeOptions: the choices of the
    bulk "Asignar gestor" form, or null for those who may not assign.

    The bulk form wraps the table, so its row boxes post without JavaScript;
    Alpine only adds "choose this page" and the count of chosen clients.
--}}
@php
    $canAssign = $assigneeOptions !== null;
    $columns = [
        ...($canAssign ? [['key' => 'choose', 'label' => __('cartera::clientes.asignar.columna')]] : []),
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
    <x-ui.page-header :title="__('cartera::clientes.titulo')" :description="__('cartera::clientes.descripcion')">
        @can('cartera.export')
            <x-slot:actions>
                <x-ui.link-button :href="route('cartera.clientes.exportar', $filter->query())" variant="secondary" icon="download">{{ __('cartera::exportar.boton') }}</x-ui.link-button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

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
        <x-ui.select
            name="gestor"
            :label="__('cartera::clientes.filtros.gestor')"
            :options="$managerOptions"
            :value="$filter->manager"
        />

        <fieldset class="grid content-start gap-2">
            <legend class="sr-only">{{ __('cartera::clientes.filtros.etiqueta') }}</legend>
            <x-ui.checkbox name="vencidos" :label="__('cartera::clientes.filtros.vencidos')" :checked="$filter->onlyOverdue" />
            <x-ui.checkbox name="sin_gestion" :label="__('cartera::clientes.filtros.sin_gestion', ['dias' => $unmanagedDays])" :checked="$filter->unmanaged" />
        </fieldset>

        <div class="flex flex-wrap items-center gap-2 md:col-span-2 lg:col-span-4">
            <x-ui.button type="submit" icon="search">{{ __('cartera::clientes.filtros.aplicar') }}</x-ui.button>
            @if ($filter->isFiltered())
                <x-ui.link-button :href="route('cartera.clientes.index', $filter->clearQuery())" variant="ghost">{{ __('cartera::clientes.filtros.limpiar') }}</x-ui.link-button>
            @endif
        </div>
    </form>

    @if ($clients->isEmpty())
        @if ($filter->isFiltered())
            <x-ui.empty icon="search" :title="__('cartera::clientes.vacio.sin_resultados.titulo')" :description="__('cartera::clientes.vacio.sin_resultados.detalle')">
                <x-ui.link-button :href="route('cartera.clientes.index', $filter->clearQuery())" variant="secondary">{{ __('cartera::clientes.vacio.sin_resultados.accion') }}</x-ui.link-button>
            </x-ui.empty>
        @else
            <x-ui.empty icon="users" :title="__('cartera::clientes.vacio.sin_clientes.titulo')" :description="__('cartera::clientes.vacio.sin_clientes.detalle')" />
        @endif
    @else
        @if ($canAssign)
            <form
                method="POST"
                action="{{ route('cartera.clientes.asignar') }}"
                x-data="{ chosen: 0, count() { this.chosen = this.$root.querySelectorAll('[data-choose-client]:checked').length } }"
                x-init="count()"
                x-on:change="count()"
            >
                @csrf
                @foreach ($filter->query() as $name => $value)
                    @continue(in_array($name, ['sort', 'direction'], true))
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach

                <section aria-labelledby="asignar-gestor-titulo" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
                    <div class="grid gap-1">
                        <h2 id="asignar-gestor-titulo" class="text-base font-bold text-fg">{{ __('cartera::clientes.asignar.titulo') }}</h2>
                        <p class="text-[0.9375rem] text-fg-muted">{{ __('cartera::clientes.asignar.descripcion') }}</p>
                    </div>

                    @if ($errors->has('clientes') || $errors->has('clientes.*'))
                        <x-ui.alert tone="danger">{{ $errors->first('clientes') ?: $errors->first('clientes.*') }}</x-ui.alert>
                    @endif

                    <div class="grid items-start gap-4 md:grid-cols-2">
                        <x-ui.select
                            name="nuevo_gestor"
                            :label="__('cartera::clientes.asignar.gestor')"
                            :options="$assigneeOptions"
                            :placeholder="__('cartera::clientes.asignar.elige')"
                            :required="true"
                        />
                        <div class="grid content-start">
                            <template x-if="true">
                                <x-ui.checkbox
                                    name="elegir_pagina"
                                    :label="__('cartera::clientes.asignar.pagina')"
                                    x-on:change="$root.querySelectorAll('[data-choose-client]').forEach(function (box) { box.checked = $event.target.checked })"
                                />
                            </template>
                            <x-ui.checkbox
                                name="todos_filtrados"
                                :label="trans_choice('cartera::clientes.asignar.todos_filtrados', $clients->total(), ['count' => number_format($clients->total(), 0, ',', '.')])"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.button type="submit">{{ __('cartera::clientes.asignar.boton') }}</x-ui.button>
                        <p aria-live="polite" class="text-[0.9375rem] text-fg-muted">
                            <span x-show="chosen > 0" x-text="chosen === 1 ? @js(__('cartera::clientes.asignar.elegido')) : @js(__('cartera::clientes.asignar.elegidos')).replace(':count', chosen)"></span>
                        </p>
                    </div>
                </section>
        @endif

        <x-ui.table
            :caption="__('cartera::clientes.tabla')"
            :caption-hidden="true"
            :columns="$columns"
            :sort-by="$filter->sort"
            :sort-direction="$filter->direction"
        >
            @foreach ($clients as $client)
                <tr>
                    @if ($canAssign)
                        <td>
                            <label class="flex size-touch cursor-pointer items-center justify-center">
                                <input
                                    type="checkbox"
                                    name="clientes[]" value="{{ $client->id }}"
                                    data-choose-client
                                    @checked(in_array((string) $client->id, array_map(strval(...), (array) old('clientes', [])), true))
                                    class="size-5 cursor-pointer rounded accent-brand-control"
                                >
                                <span data-visually-hidden>{{ __('cartera::clientes.asignar.elegir', ['cliente' => $client->name]) }}</span>
                            </label>
                        </td>
                    @endif
                    <td class="min-w-44"><x-cartera::client-link :id="$client->id" :name="$client->name" /></td>
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

        @if ($canAssign)
            </form>
        @endif
    @endif
</x-layouts.app>
