{{--
    GET /empresas (core.empresas.index): the parameters of the client
    statuses of each company, for admins, with a link to edit them.
    $companies: list<CompanyParameters>, in CompanyCode order.
--}}
@php
    $columns = [
        ['key' => 'company', 'label' => __('core::empresas.parametros.columnas.empresa')],
        ['key' => 'grace_days', 'label' => __('core::empresas.parametros.columnas.dias_gracia'), 'align' => 'end'],
        ['key' => 'due_soon_days', 'label' => __('core::empresas.parametros.columnas.ventana'), 'align' => 'end'],
        ['key' => 'actions', 'label' => __('core::empresas.parametros.columnas.acciones'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('core::empresas.parametros.titulo')">
    <x-ui.page-header :title="__('core::empresas.parametros.titulo')" :description="__('core::empresas.parametros.descripcion')" />

    <x-ui.table :caption="__('core::empresas.parametros.tabla')" :caption-hidden="true" :columns="$columns">
        @foreach ($companies as $company)
            <tr>
                <td>{{ __('core::empresas.parametros.empresa', ['nombre' => $company->name, 'codigo' => $company->code->value]) }}</td>
                <td data-align="end">{{ trans_choice('core::empresas.parametros.dias', $company->graceDays) }}</td>
                <td data-align="end">{{ trans_choice('core::empresas.parametros.dias', $company->dueSoonDays) }}</td>
                <td data-align="end">
                    <x-ui.link-button :href="route('core.empresas.edit', $company->code->value)" variant="ghost" icon="edit">{{ __('core::empresas.parametros.acciones.editar') }}<span data-visually-hidden> {{ __('core::empresas.parametros.acciones.de', ['empresa' => $company->name]) }}</span></x-ui.link-button>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</x-layouts.app>
