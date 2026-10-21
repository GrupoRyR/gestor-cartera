@use('App\Support\Formatting\DateFormat')

{{--
    GET /cobro/exclusiones (cobro.exclusiones.index): the exclusion list.
    $clients: paginator of ClientSummary (overdue first; with $all, every
    client), filtered by $search; $excludedOnPage: client id => Exclusion
    of the clients on this page; $excluded: paginator (?excluidos=) of the
    Exclusion rows of the user's companies; $isGroup: the group view shows
    each client's company; $today: today in Bogotá.
--}}
@php
    $clientColumns = [
        ['key' => 'name', 'label' => __('cobro::exclusiones.clientes.columnas.cliente')],
        ...($isGroup ? [['key' => 'company', 'label' => __('cobro::exclusiones.clientes.columnas.empresa')]] : []),
        ['key' => 'tax_id', 'label' => __('cobro::exclusiones.clientes.columnas.nit')],
        ['key' => 'overdue', 'label' => __('cobro::exclusiones.clientes.columnas.vencido'), 'align' => 'end'],
        ['key' => 'days', 'label' => __('cobro::exclusiones.clientes.columnas.dias'), 'align' => 'end'],
        ['key' => 'state', 'label' => __('cobro::exclusiones.clientes.columnas.estado')],
    ];
    $excludedColumns = [
        ['key' => 'name', 'label' => __('cobro::exclusiones.excluidos.columnas.cliente')],
        ...($isGroup ? [['key' => 'company', 'label' => __('cobro::exclusiones.excluidos.columnas.empresa')]] : []),
        ['key' => 'reason', 'label' => __('cobro::exclusiones.excluidos.columnas.motivo')],
        ['key' => 'expires', 'label' => __('cobro::exclusiones.excluidos.columnas.vence')],
        ['key' => 'by', 'label' => __('cobro::exclusiones.excluidos.columnas.por')],
        ['key' => 'since', 'label' => __('cobro::exclusiones.excluidos.columnas.desde')],
    ];
@endphp

<x-layouts.app :title="__('cobro::exclusiones.titulo')">
    <x-ui.page-header :title="__('cobro::exclusiones.titulo')" :description="__('cobro::exclusiones.descripcion')" />

    <div class="flex flex-col gap-6">
        <form method="GET" action="{{ route('cobro.exclusiones.index') }}" role="search" aria-label="{{ __('cobro::exclusiones.buscar.etiqueta') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[min(14rem,100%)] flex-1">
                <x-ui.input name="buscar" type="search" :label="__('cobro::exclusiones.buscar.campo')" :value="$search" />
            </div>
            <x-ui.checkbox name="todos" :label="__('cobro::exclusiones.buscar.todos')" :checked="$all" />
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="secondary" icon="search">{{ __('cobro::exclusiones.buscar.aplicar') }}</x-ui.button>
                @if ($search !== '' || $all)
                    <x-ui.link-button :href="route('cobro.exclusiones.index')" variant="ghost">{{ __('cobro::exclusiones.buscar.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        <x-ui.card :title="__('cobro::exclusiones.clientes.titulo')" :description="__('cobro::exclusiones.clientes.descripcion')">
            @if ($clients->isEmpty())
                <x-ui.empty icon="search" :title="__('cobro::exclusiones.clientes.vacio.titulo')" :description="__('cobro::exclusiones.clientes.vacio.detalle')" />
            @else
                <form method="POST" action="{{ route('cobro.exclusiones.store') }}" class="flex flex-col gap-4">
                    @csrf

                    <x-ui.table :caption="__('cobro::exclusiones.clientes.tabla')" :caption-hidden="true" :columns="$clientColumns">
                        @foreach ($clients as $client)
                            @php($exclusion = $excludedOnPage->get($client->id))
                            <tr data-client="{{ $client->id }}">
                                <td class="min-w-44">
                                    <x-ui.checkbox name="clientes[]" :value="$client->id" :label="$client->name" />
                                </td>
                                @if ($isGroup)
                                    <td><x-ui.chip :dot="false">{{ $client->company->value }}</x-ui.chip></td>
                                @endif
                                <td>{{ $client->taxId }}</td>
                                <td data-align="end"><x-ui.money :value="$client->overdue" /></td>
                                <td data-align="end">{{ $client->maxDaysOverdue > 0 ? number_format($client->maxDaysOverdue, 0, ',', '.') : '—' }}</td>
                                <td>
                                    @if ($exclusion === null)
                                        —
                                    @elseif ($exclusion->expires_on === null)
                                        <x-ui.chip tone="warning">{{ __('cobro::exclusiones.clientes.excluido') }}</x-ui.chip>
                                    @else
                                        <x-ui.chip tone="warning">{{ __('cobro::exclusiones.clientes.excluido_hasta', ['fecha' => DateFormat::date($exclusion->expires_on)]) }}</x-ui.chip>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    <x-ui.pagination :paginator="$clients" />

                    <div class="grid gap-4 md:grid-cols-2">
                        <x-ui.input name="motivo" :label="__('cobro::exclusiones.formulario.motivo')" :hint="__('cobro::exclusiones.formulario.motivo_ayuda')" :required="true" maxlength="500" />
                        <x-ui.input name="vence" type="date" :label="__('cobro::exclusiones.formulario.vence')" :hint="__('cobro::exclusiones.formulario.vence_ayuda')" :min="$today->format('Y-m-d')" />
                    </div>

                    <div>
                        <x-ui.button type="submit" icon="check">{{ __('cobro::exclusiones.formulario.guardar') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('cobro::exclusiones.excluidos.titulo')" :description="__('cobro::exclusiones.excluidos.descripcion')">
            @if ($excluded->isEmpty())
                <x-ui.empty icon="check" :title="__('cobro::exclusiones.excluidos.vacio.titulo')" :description="__('cobro::exclusiones.excluidos.vacio.detalle')" />
            @else
                <form method="POST" action="{{ route('cobro.exclusiones.destroy') }}" class="flex flex-col gap-4">
                    @csrf

                    <x-ui.table :caption="__('cobro::exclusiones.excluidos.tabla')" :caption-hidden="true" :columns="$excludedColumns">
                        @foreach ($excluded as $exclusion)
                            <tr data-exclusion="{{ $exclusion->id }}">
                                <td class="min-w-44">
                                    <x-ui.checkbox name="exclusiones[]" :value="$exclusion->id" :label="$exclusion->client_name" />
                                </td>
                                @if ($isGroup)
                                    <td><x-ui.chip :dot="false">{{ $exclusion->company->value }}</x-ui.chip></td>
                                @endif
                                <td class="[overflow-wrap:anywhere]">{{ $exclusion->reason }}</td>
                                <td>
                                    @if ($exclusion->expires_on === null)
                                        {{ __('cobro::exclusiones.excluidos.sin_vencimiento') }}
                                    @else
                                        <x-ui.date :value="$exclusion->expires_on" />
                                        @if ($exclusion->expires_on->format('Y-m-d') < $today->format('Y-m-d'))
                                            <x-ui.chip tone="neutral">{{ __('cobro::exclusiones.excluidos.vencida') }}</x-ui.chip>
                                        @endif
                                    @endif
                                </td>
                                <td>{{ $exclusion->creator?->name ?? '—' }}</td>
                                <td><x-ui.date :value="$exclusion->created_at" /></td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    <x-ui.pagination :paginator="$excluded" />

                    <div>
                        <x-ui.button type="submit" variant="secondary" icon="close">{{ __('cobro::exclusiones.excluidos.quitar') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
