{{--
    GET /bancos/cargas (bancos.cargas.index): the statements loaded so far,
    newest first, 25 per page, and (with bancos.import) the form to upload
    one by hand.
    $loads: paginator of BankLoad with user; $companies: value => label of the
    companies the user may load for; $banks: value => label.
--}}
@php
    $columns = [
        ['key' => 'created_at', 'label' => __('bancos::cargas.columnas.fecha')],
        ['key' => 'source', 'label' => __('bancos::cargas.columnas.origen')],
        ['key' => 'bank', 'label' => __('bancos::cargas.columnas.banco')],
        ['key' => 'company', 'label' => __('bancos::cargas.columnas.empresa')],
        ['key' => 'file', 'label' => __('bancos::cargas.columnas.archivo')],
        ['key' => 'inserted', 'label' => __('bancos::cargas.columnas.nuevos'), 'align' => 'end'],
        ['key' => 'duplicates', 'label' => __('bancos::cargas.columnas.repetidos'), 'align' => 'end'],
        ['key' => 'errors', 'label' => __('bancos::cargas.columnas.errores'), 'align' => 'end'],
        ['key' => 'status', 'label' => __('bancos::cargas.columnas.estado')],
        ['key' => 'actions', 'label' => __('bancos::cargas.columnas.acciones'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('bancos::cargas.titulo')">
    <x-ui.page-header :title="__('bancos::cargas.titulo')" :description="__('bancos::cargas.descripcion')" />

    <div class="flex flex-col gap-6">
        @can('bancos.import')
            <x-ui.card :title="__('bancos::cargas.formulario.titulo')" :description="__('bancos::cargas.formulario.descripcion')">
                <form method="POST" action="{{ route('bancos.cargas.store') }}" enctype="multipart/form-data" class="grid max-w-3xl gap-4 md:grid-cols-2">
                    @csrf

                    <x-ui.select
                        name="empresa"
                        :label="__('bancos::cargas.formulario.empresa')"
                        :options="$companies"
                        :value="count($companies) === 1 ? array_key_first($companies) : null"
                        :placeholder="count($companies) === 1 ? null : __('bancos::cargas.formulario.empresa_elige')"
                        :required="true"
                    />

                    <x-ui.select
                        name="banco"
                        :label="__('bancos::cargas.formulario.banco')"
                        :options="$banks"
                        :placeholder="__('bancos::cargas.formulario.banco_elige')"
                        :required="true"
                    />

                    <div class="md:col-span-2">
                        <x-ui.input
                            name="archivo"
                            type="file"
                            :label="__('bancos::cargas.formulario.archivo')"
                            :hint="__('bancos::cargas.formulario.archivo_ayuda')"
                            :required="true"
                            accept=".xlsx,.xls,.csv,.txt"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <x-ui.button type="submit" icon="upload">{{ __('bancos::cargas.formulario.enviar') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endcan

        @if ($loads->isEmpty())
            <x-ui.empty
                icon="bank"
                :title="__('bancos::cargas.vacio.titulo')"
                :description="__('bancos::cargas.vacio.detalle')"
            />
        @else
            <div>
                <x-ui.table :caption="__('bancos::cargas.tabla')" :caption-hidden="true" :columns="$columns">
                    @foreach ($loads as $load)
                        <tr>
                            <td><x-ui.date :value="$load->created_at" :time="true" /></td>
                            <td>{{ $load->source->label() }}</td>
                            <td>{{ $load->bank->label() }}</td>
                            <td>{{ $load->company->value }}</td>
                            <td>{{ $load->file_name ?? __('bancos::cargas.sin_archivo') }}</td>
                            <td data-align="end">{{ number_format($load->inserted, 0, ',', '.') }}</td>
                            <td data-align="end">{{ number_format($load->duplicates, 0, ',', '.') }}</td>
                            <td data-align="end">{{ number_format($load->error_count, 0, ',', '.') }}</td>
                            <td>
                                @if ($load->failed)
                                    <x-ui.chip tone="danger">{{ __('bancos::cargas.estado.fallida') }}</x-ui.chip>
                                @elseif ($load->error_count > 0)
                                    <x-ui.chip tone="warning">{{ __('bancos::cargas.estado.con_errores') }}</x-ui.chip>
                                @else
                                    <x-ui.chip tone="success">{{ __('bancos::cargas.estado.ok') }}</x-ui.chip>
                                @endif
                            </td>
                            <td data-align="end">
                                <x-ui.link-button :href="route('bancos.cargas.show', $load)" variant="ghost" icon="eye">{{ __('bancos::cargas.ver') }}<span data-visually-hidden> {{ __('bancos::cargas.ver_de', ['archivo' => $load->file_name ?? $load->bank->label()]) }}</span></x-ui.link-button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$loads" />
            </div>
        @endif
    </div>
</x-layouts.app>
