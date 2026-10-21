@use('App\Modules\Core\Contracts\Enums\CompanyCode')
@use('App\Modules\Core\Contracts\Enums\Dataset')
@use('App\Modules\Core\Contracts\Enums\LoadStatus')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /ingesta (ingesta.index): the load history, 25 per page, newest
    received first, with GET filters by dataset (lote), company (empresa)
    and status (estado). Above it, the current cartera of each company in
    ingesta.alert_companies and a warning per company still missing today's
    robot load after the deadline.
    $loads: paginator of IngestaLoad with receivedBy; $filters: the valid
    filter values; $current: list of ['company' => CompanyCode, 'load' => ?LoadRecord];
    $missing: list<CompanyCode>; $deadline: today's deadline.
--}}
@php
    $columns = [
        ['key' => 'received_at', 'label' => __('ingesta::historial.columnas.recibida')],
        ['key' => 'dataset', 'label' => __('ingesta::historial.columnas.lote')],
        ['key' => 'company', 'label' => __('ingesta::historial.columnas.empresa')],
        ['key' => 'cutoff_date', 'label' => __('ingesta::historial.columnas.corte')],
        ['key' => 'source', 'label' => __('ingesta::historial.columnas.origen')],
        ['key' => 'status', 'label' => __('ingesta::historial.columnas.estado')],
        ['key' => 'rows', 'label' => __('ingesta::historial.columnas.filas'), 'align' => 'end'],
        ['key' => 'total', 'label' => __('ingesta::historial.columnas.total'), 'align' => 'end'],
        ['key' => 'overdue', 'label' => __('ingesta::historial.columnas.vencido'), 'align' => 'end'],
        ['key' => 'user', 'label' => __('ingesta::historial.columnas.usuario')],
        ['key' => 'actions', 'label' => __('ingesta::historial.columnas.acciones'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('ingesta::historial.titulo')">
    <x-ui.page-header :title="__('ingesta::historial.titulo')" :description="__('ingesta::historial.descripcion')">
        @can('ingesta.upload')
            <x-slot:actions>
                <x-ui.link-button :href="route('ingesta.manual.create')" icon="upload">{{ __('ingesta::manual.boton') }}</x-ui.link-button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @foreach ($missing as $company)
            <x-ui.alert tone="warning" :title="__('ingesta::historial.alerta.titulo', ['empresa' => $company->label()])">
                {{ __('ingesta::historial.alerta.detalle', ['hora' => $deadline->format('H:i'), 'fecha' => DateFormat::date($deadline)]) }}
            </x-ui.alert>
        @endforeach

        @foreach ($current as $entry)
            <x-ui.card
                :title="__('ingesta::historial.vigente.titulo', ['empresa' => $entry['company']->label()])"
                :description="__('ingesta::historial.vigente.descripcion')"
            >
                @if ($entry['load'] === null)
                    <p>{{ __('ingesta::historial.vigente.sin_carga') }}</p>
                @else
                    <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
                        <x-ui.stat :label="__('ingesta::historial.vigente.corte')" :value="DateFormat::date($entry['load']->cutoffDate)" />
                        <x-ui.stat :label="__('ingesta::historial.vigente.origen')" :value="$entry['load']->source->label()" />
                        <x-ui.stat :label="__('ingesta::historial.vigente.total')">
                            <x-ui.money :value="$entry['load']->total" />
                        </x-ui.stat>
                        <x-ui.stat :label="__('ingesta::historial.vigente.vencido')">
                            <x-ui.money :value="$entry['load']->overdue" />
                        </x-ui.stat>
                    </div>
                @endif
            </x-ui.card>
        @endforeach

        <form method="GET" action="{{ route('ingesta.index') }}" aria-label="{{ __('ingesta::historial.filtros.etiqueta') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[min(12rem,100%)] flex-1">
                <x-ui.select name="lote" :label="__('ingesta::historial.filtros.lote')" :options="Dataset::options()" :value="$filters['lote']" :placeholder="__('ingesta::historial.filtros.todos')" />
            </div>
            <div class="min-w-[min(12rem,100%)] flex-1">
                <x-ui.select name="empresa" :label="__('ingesta::historial.filtros.empresa')" :options="CompanyCode::options()" :value="$filters['empresa']" :placeholder="__('ingesta::historial.filtros.todas')" />
            </div>
            <div class="min-w-[min(12rem,100%)] flex-1">
                <x-ui.select name="estado" :label="__('ingesta::historial.filtros.estado')" :options="LoadStatus::options()" :value="$filters['estado']" :placeholder="__('ingesta::historial.filtros.todos')" />
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter">{{ __('ingesta::historial.filtros.aplicar') }}</x-ui.button>
                @if ($filtered)
                    <x-ui.link-button :href="route('ingesta.index')" variant="ghost">{{ __('ingesta::historial.filtros.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        @if ($loads->isEmpty())
            <x-ui.empty
                icon="history"
                :title="__($filtered ? 'ingesta::historial.vacio_filtrado.titulo' : 'ingesta::historial.vacio.titulo')"
                :description="__($filtered ? 'ingesta::historial.vacio_filtrado.detalle' : 'ingesta::historial.vacio.detalle')"
            />
        @else
            <div>
                <x-ui.table :caption="__('ingesta::historial.tabla')" :caption-hidden="true" :columns="$columns">
                    @foreach ($loads as $load)
                        @php($cartera = $load->dataset === Dataset::Receivables)
                        <tr data-batch="{{ $load->batch_id }}">
                            <td><x-ui.date :value="$load->received_at" :time="true" /></td>
                            <td>{{ $load->dataset->label() }}</td>
                            <td>{{ $load->company->value }}</td>
                            <td><x-ui.date :value="$load->cutoff_date" /></td>
                            <td>{{ $load->source->label() }}</td>
                            <td>
                                <x-ui.chip :tone="$load->status === LoadStatus::Accepted ? 'success' : 'danger'">{{ $load->status->label() }}</x-ui.chip>
                            </td>
                            <td data-align="end">{{ number_format($load->row_count, 0, ',', '.') }}</td>
                            <td data-align="end"><x-ui.money :value="$cartera ? $load->total : null" /></td>
                            <td data-align="end"><x-ui.money :value="$cartera ? $load->overdue : null" /></td>
                            <td>{{ $load->receivedBy?->name ?? '—' }}</td>
                            <td data-align="end">
                                <x-ui.link-button :href="route('ingesta.cargas.show', $load)" variant="ghost" icon="eye">{{ __('ingesta::historial.ver') }}<span data-visually-hidden> {{ __('ingesta::historial.ver_de', ['lote' => mb_strtolower($load->dataset->label()), 'fecha' => DateFormat::date($load->cutoff_date)]) }}</span></x-ui.link-button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$loads" />
            </div>
        @endif
    </div>
</x-layouts.app>
