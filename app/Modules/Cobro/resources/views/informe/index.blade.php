@use('App\Modules\Cobro\Application\Reports\MailReportFilter')
@use('App\Modules\Cobro\Application\Reports\ReportNumbers')
@use('App\Modules\Cobro\Domain\Runs\ItemStatus')
@use('App\Modules\Cobro\Domain\Runs\RunKind')
@use('App\Modules\Cobro\Infrastructure\Reports\MailReport')

{{--
    GET /cobro/informe (cobro.informe.index): the collection report.
    $filter: MailReportFilter; $items: paginator of RunItem with its run and
    first_reply_at; $metrics: MailReportMetrics over every filtered item;
    $clients: ReportClients (current advisor and group of each client);
    $commitments: CommitmentWindow of the filter; $advisorOptions:
    "<company>:<code>" => name; $groupOptions: group => group, or null
    without groups; $companyOptions: code => name, or null with a single
    company in scope; $query: the filters in the URL, without the page (for
    the Excel link); $narrowed: whether a filter is in the URL.
--}}
@php
    $number = fn (int $value): string => number_format($value, 0, ',', '.');
    $columns = [
        ['key' => 'date', 'label' => __('cobro::informe.columnas.fecha')],
        ['key' => 'company', 'label' => __('cobro::informe.columnas.empresa')],
        ['key' => 'kind', 'label' => __('cobro::informe.columnas.tipo')],
        ['key' => 'client', 'label' => __('cobro::informe.columnas.cliente')],
        ['key' => 'group', 'label' => __('cobro::informe.columnas.grupo')],
        ['key' => 'advisor', 'label' => __('cobro::informe.columnas.asesor')],
        ['key' => 'status', 'label' => __('cobro::informe.columnas.estado')],
        ['key' => 'amount', 'label' => __('cobro::informe.columnas.monto'), 'align' => 'end'],
        ['key' => 'invoices', 'label' => __('cobro::informe.columnas.facturas'), 'align' => 'end'],
        ['key' => 'sent', 'label' => __('cobro::informe.columnas.enviado')],
        ['key' => 'reply', 'label' => __('cobro::informe.columnas.respuesta')],
        ['key' => 'commitment', 'label' => __('cobro::informe.columnas.compromiso')],
    ];
    $kindOptions = [];
    foreach (RunKind::cases() as $kind) {
        $kindOptions[$kind->value] = $kind->label();
    }
    $statusOptions = [];
    foreach (ItemStatus::cases() as $status) {
        $statusOptions[$status->value] = $status->label();
    }
    $advisorKey = $filter->advisorCompany === null || $filter->advisorCode === null ? null : MailReportFilter::advisorKey($filter->advisorCompany, $filter->advisorCode);
@endphp

<x-layouts.app :title="__('cobro::informe.titulo')">
    <x-ui.page-header :title="__('cobro::informe.titulo')" :description="__('cobro::informe.descripcion')">
        @can('cobro.export')
            <x-slot:actions>
                <x-ui.link-button :href="route('cobro.informe.export', $query)" variant="secondary" icon="download">{{ __('cobro::informe.exportar.boton') }}</x-ui.link-button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        <form method="GET" action="{{ route('cobro.informe.index') }}" role="search" aria-label="{{ __('cobro::informe.filtros.etiqueta') }}" class="grid gap-4 rounded-card border border-line bg-surface p-4 md:grid-cols-2 md:p-5 lg:grid-cols-4">
            <x-ui.input name="desde" type="date" :label="__('cobro::informe.filtros.desde')" :value="$filter->from->format('Y-m-d')" />
            <x-ui.input name="hasta" type="date" :label="__('cobro::informe.filtros.hasta')" :value="$filter->to->format('Y-m-d')" />
            @if ($companyOptions !== null)
                <x-ui.select name="empresa" :label="__('cobro::informe.filtros.empresa')" :options="$companyOptions" :value="count($filter->companies) === 1 ? $filter->companies[0]->value : null" :placeholder="__('cobro::informe.filtro.todas')" />
            @endif
            <x-ui.input name="cliente" type="search" :label="__('cobro::informe.filtros.cliente')" :value="$filter->client" :hint="__('cobro::informe.filtro.cliente_ayuda')" />
            <x-ui.select name="asesor" :label="__('cobro::informe.filtros.asesor')" :options="$advisorOptions" :value="$advisorKey" :placeholder="__('cobro::informe.filtro.todos')" />
            @if ($groupOptions !== null)
                <x-ui.select name="grupo" :label="__('cobro::informe.filtros.grupo')" :options="$groupOptions" :value="$filter->group" :placeholder="__('cobro::informe.filtro.todos')" :hint="__('cobro::informe.filtro.grupo_ayuda')" />
            @endif
            <x-ui.select name="tipo" :label="__('cobro::informe.filtros.tipo')" :options="$kindOptions" :value="$filter->kind" :placeholder="__('cobro::informe.filtro.todos')" />
            <x-ui.select name="estado" :label="__('cobro::informe.filtros.estado')" :options="$statusOptions" :value="$filter->status" :placeholder="__('cobro::informe.filtro.todos')" />
            <x-ui.select
                name="respondio"
                :label="__('cobro::informe.filtros.respondio')"
                :options="['si' => __('cobro::informe.filtro.si'), 'no' => __('cobro::informe.filtro.no')]"
                :value="$filter->replied === null ? null : ($filter->replied ? 'si' : 'no')"
                :placeholder="__('cobro::informe.filtro.todos')"
                :hint="__('cobro::informe.filtro.respondio_ayuda')"
            />
            <div class="flex flex-wrap items-center gap-2 md:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" icon="filter">{{ __('cobro::informe.filtro.aplicar') }}</x-ui.button>
                @if ($narrowed)
                    <x-ui.link-button :href="route('cobro.informe.index')" variant="ghost">{{ __('cobro::informe.filtro.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat data-metric="sent" :label="__('cobro::informe.metricas.enviados')" :value="$number($metrics->sent)" :hint="__('cobro::informe.metricas.enviados_ayuda')" />
            <x-ui.stat data-metric="failed" :label="__('cobro::informe.metricas.fallidos')" :value="$number($metrics->failed)" :tone="$metrics->failed > 0 ? 'danger' : 'neutral'" />
            <x-ui.stat data-metric="bounced" :label="__('cobro::informe.metricas.rebotados')" :value="$number($metrics->bounced)" :tone="$metrics->bounced > 0 ? 'danger' : 'neutral'" />
            <x-ui.stat data-metric="clients" :label="__('cobro::informe.metricas.clientes')" :value="$number($metrics->clients)" />
        </div>

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat data-metric="replies" :label="__('cobro::informe.metricas.respuestas')" :value="$number($metrics->replies)" :hint="__('cobro::informe.metricas.respuestas_ayuda')" />
            <x-ui.stat data-metric="rate" :label="__('cobro::informe.metricas.tasa')" :value="ReportNumbers::percent($metrics->replies, $metrics->sent)" />
            <x-ui.stat data-metric="median" :label="__('cobro::informe.metricas.mediana')" :value="ReportNumbers::duration($metrics->medianFirstReply)" />
            <x-ui.stat data-metric="team" :label="__('cobro::informe.metricas.equipo')" :value="$number($metrics->teamReplies)" :hint="__('cobro::informe.metricas.equipo_ayuda')" />
        </div>

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat data-metric="commitments" :label="__('cobro::informe.metricas.compromisos')" :value="$number($metrics->committed)" :hint="__('cobro::informe.metricas.compromisos_ayuda')" />
            <x-ui.stat data-metric="commitment_rate" :label="__('cobro::informe.metricas.tasa_compromisos')" :value="ReportNumbers::percent($metrics->committed, $metrics->sent)" />
        </div>

        @if ($items->isEmpty())
            <x-ui.empty icon="chart" :title="__('cobro::informe.vacio.titulo')" :description="__('cobro::informe.vacio.detalle')" />
        @else
            <div>
                <x-ui.table :caption="__('cobro::informe.tabla')" :caption-hidden="true" :columns="$columns">
                    @foreach ($items as $item)
                        <tr data-item="{{ $item->id }}">
                            <td><x-ui.date :value="$item->run->scheduled_for" /></td>
                            <td><x-ui.chip :dot="false">{{ $item->run->company->value }}</x-ui.chip></td>
                            <td>{{ $item->run->kind->label() }}</td>
                            <td class="min-w-44">
                                <span class="block text-sm">{{ $item->client_code }}</span>
                                <span class="font-semibold">{{ $item->client_name }}</span>
                            </td>
                            <td>{{ $clients->group($item->client_id) ?? '—' }}</td>
                            <td>{{ $clients->advisorName($item->client_id) ?? '—' }}</td>
                            <td><x-ui.chip :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.chip></td>
                            <td data-align="end"><x-ui.money :value="$item->amount" /></td>
                            <td data-align="end">{{ $number($item->invoice_count) }}</td>
                            <td><x-ui.date :value="$item->sent_at" :time="true" /></td>
                            <td><x-ui.date :value="$item->first_reply_at" :time="true" :fallback="__('cobro::informe.sin_respuesta')" /></td>
                            <td><x-ui.date :value="$commitments->first($item->client_id, MailReport::mailed($item) ? $item->sent_at : null)" :time="true" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$items" />
            </div>
        @endif
    </div>
</x-layouts.app>
