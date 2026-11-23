{{--
    GET /soportes/nuevo (soportes.create), step 1: the overdue clients the
    user may upload for. $clients: paginator of ClientSummary filtered by
    $search (name or NIT); $isGroup: the group view shows each client's
    company. Each client links to step 2 (?cliente={id}).
--}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('soportes::carga.clientes.columnas.cliente')],
        ...($isGroup ? [['key' => 'company', 'label' => __('soportes::carga.clientes.columnas.empresa')]] : []),
        ['key' => 'tax_id', 'label' => __('soportes::carga.clientes.columnas.nit')],
        ['key' => 'overdue', 'label' => __('soportes::carga.clientes.columnas.vencido'), 'align' => 'end'],
        ['key' => 'days', 'label' => __('soportes::carga.clientes.columnas.dias'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('soportes::carga.titulo')">
    <x-ui.page-header :context="__('soportes::carga.contexto')" :title="__('soportes::carga.titulo')" :description="__('soportes::carga.clientes.descripcion')" />

    <div class="flex flex-col gap-6">
        <form method="GET" action="{{ route('soportes.create') }}" role="search" aria-label="{{ __('soportes::carga.clientes.buscar.etiqueta') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[min(14rem,100%)] flex-1">
                <x-ui.input name="buscar" type="search" :label="__('soportes::carga.clientes.buscar.campo')" :value="$search" />
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="secondary" icon="search">{{ __('soportes::carga.clientes.buscar.aplicar') }}</x-ui.button>
                @if ($search !== '')
                    <x-ui.link-button :href="route('soportes.create')" variant="ghost">{{ __('soportes::carga.clientes.buscar.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        <x-ui.card>
            @if ($clients->isEmpty())
                <x-ui.empty icon="search" :title="__('soportes::carga.clientes.vacio.titulo')" :description="__('soportes::carga.clientes.vacio.detalle')" />
            @else
                <x-ui.table :caption="__('soportes::carga.clientes.tabla')" :caption-hidden="true" :columns="$columns">
                    @foreach ($clients as $client)
                        <tr data-client="{{ $client->id }}">
                            <td class="min-w-44">
                                <a href="{{ route('soportes.create', ['cliente' => $client->id]) }}" class="font-semibold text-brand-ink underline-offset-4 hover:underline">{{ $client->name }}</a>
                            </td>
                            @if ($isGroup)
                                <td><x-ui.chip :dot="false">{{ $client->company->value }}</x-ui.chip></td>
                            @endif
                            <td>{{ $client->taxId }}</td>
                            <td data-align="end"><x-ui.money :value="$client->overdue" /></td>
                            <td data-align="end">{{ number_format($client->maxDaysOverdue, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$clients" />
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
