@use('App\Support\Formatting\DateFormat')

{{--
    GET /bancos/cargas/{load} (bancos.cargas.show): what the importer did with
    one statement and the rows it could not read (the first 50).
    $load: BankLoad with user.
--}}
@php
    $columns = [
        ['key' => 'row', 'label' => __('bancos::cargas.detalle.fila')],
        ['key' => 'message', 'label' => __('bancos::cargas.detalle.mensaje')],
    ];
@endphp

<x-layouts.app :title="__('bancos::cargas.detalle.titulo', ['banco' => $load->bank->label(), 'empresa' => $load->company->value])">
    <x-ui.page-header
        :context="__('bancos::cargas.detalle.contexto')"
        :title="__('bancos::cargas.detalle.titulo', ['banco' => $load->bank->label(), 'empresa' => $load->company->value])"
        :description="__('bancos::cargas.detalle.descripcion', ['origen' => $load->source->label(), 'fecha' => DateFormat::dateTime($load->created_at)])"
    >
        <x-slot:actions>
            <x-ui.link-button :href="route('bancos.cargas.index')" variant="secondary" icon="chevron-left">{{ __('bancos::cargas.detalle.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if ($load->file_name)
            <p class="text-fg-soft">{{ $load->file_name }}</p>
        @endif

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-3">
            <x-ui.stat :label="__('bancos::cargas.detalle.leidos')" :value="number_format($load->movements_read, 0, ',', '.')" />
            <x-ui.stat :label="__('bancos::cargas.detalle.creditos')" :value="number_format($load->credits, 0, ',', '.')" />
            <x-ui.stat :label="__('bancos::cargas.detalle.nuevos')" :value="number_format($load->inserted, 0, ',', '.')" tone="success" />
            <x-ui.stat :label="__('bancos::cargas.detalle.repetidos')" :value="number_format($load->duplicates, 0, ',', '.')" />
            <x-ui.stat :label="__('bancos::cargas.detalle.debitos')" :value="number_format($load->debits_skipped, 0, ',', '.')" />
            <x-ui.stat :label="__('bancos::cargas.detalle.ignoradas')" :value="number_format($load->ignored_rows, 0, ',', '.')" />
        </div>

        <x-ui.card :title="__('bancos::cargas.detalle.errores_titulo')" :description="__('bancos::cargas.detalle.errores_descripcion')">
            @if (empty($load->errors))
                <p>{{ __('bancos::cargas.detalle.sin_errores') }}</p>
            @else
                <x-ui.table :caption="__('bancos::cargas.detalle.errores_titulo')" :caption-hidden="true" :columns="$columns">
                    @foreach ($load->errors as $error)
                        <tr>
                            <td>{{ $error['row'] ?? __('bancos::cargas.detalle.general') }}</td>
                            <td>{{ $error['message'] }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>

                @if ($load->error_count > count($load->errors))
                    <p class="mt-3 text-sm text-fg-soft">{{ __('bancos::cargas.detalle.recortado') }}</p>
                @endif
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
