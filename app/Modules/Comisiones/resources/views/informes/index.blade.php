@use('App\Modules\Comisiones\Infrastructure\Audit\AuditTrail')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Modules\Comisiones\Infrastructure\Report\ReportArchive')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    GET /comisiones/periodos/{periodo}/informes (comisiones.periodos.informes.index):
    the reports of a closed version (§7.7, §8.2) and its payroll file (§8.1 annex).
    $selected: SelectedSettlement;
    $closed: whether the version shown is closed; $reports: list<CollectionReport>;
    $stored: array<"tipo:referencia", DocumentRecord>; $lastClosed: the last
    closed version of the period when the one shown is not closed.
    Only «Generar» generates: a download link shows once its document is stored.
--}}
@php
    $settlement = $selected->settlement;
    $version = $selected->versionQuery();
    $query = ['periodo' => $period->key(), ...($version === null ? [] : ['version' => $version])];
    $title = __('comisiones::informes.titulo_pagina', ['mes' => Labels::month($period)]);
    $columns = [];
    $allStored = $reports !== [] && array_diff(array_map(fn ($report): string => ReportArchive::key(ReportArchive::COLLECTION, $report->reference), $reports), array_keys($stored)) === [];

    foreach (['codigo' => 'start', 'comercial' => 'start', 'transacciones' => 'end', 'valor_pagado' => 'end', 'consolidado' => 'end', 'total_pdf' => 'end', 'cuadre' => 'start', 'archivo' => 'start', 'acciones' => 'end'] as $column => $align) {
        $columns[] = ['key' => $column, 'label' => __('comisiones::informes.recaudo.columnas.'.$column), 'align' => $align];
    }
@endphp

<x-layouts.app :title="$title">
    <x-comisiones::period-header :period="$period" :title="$title" current="informes" :version="$version" :description="__('comisiones::informes.descripcion')">
        @if ($closed)
            <x-slot:actions>
                <form method="POST" action="{{ route('comisiones.periodos.informes.generar', $query) }}">
                    @csrf
                    <x-ui.button type="submit" icon="document">{{ __('comisiones::informes.acciones.generar') }}</x-ui.button>
                </form>
                @if ($allStored)
                    <x-ui.link-button :href="route('comisiones.periodos.informes.zip', $query)" variant="secondary" icon="download">{{ __('comisiones::informes.acciones.zip') }}</x-ui.link-button>
                @endif
            </x-slot:actions>
        @endif
    </x-comisiones::period-header>

    @if (! $closed)
        <x-ui.empty icon="document" :title="__('comisiones::informes.solo_cerrada')"
            :description="$settlement === null ? __('comisiones::informes.sin_liquidacion') : __('comisiones::informes.solo_cerrada_detalle')">
            @if ($lastClosed !== null)
                <x-ui.link-button :href="route('comisiones.periodos.informes.index', ['periodo' => $period->key(), 'version' => $lastClosed])" variant="secondary">
                    {{ __('comisiones::informes.ver_cerrada', ['version' => $lastClosed]) }}
                </x-ui.link-button>
            @endif
            <x-ui.link-button :href="route('comisiones.periodos.show', $period->key())" variant="secondary">{{ __('comisiones::informes.ir_al_periodo') }}</x-ui.link-button>
        </x-ui.empty>
    @else
        <div class="flex flex-col gap-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.chip :tone="Labels::statusTone($settlement->status)">{{ Labels::status($settlement->status) }}</x-ui.chip>
                <span class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::comun.version_revision', ['version' => $settlement->version, 'revision' => $settlement->revision]) }}</span>
            </div>

            <x-ui.card :title="__('comisiones::informes.recaudo.titulo')">
                <x-ui.table :caption="__('comisiones::informes.recaudo.tabla')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::informes.recaudo.vacio')">
                    @foreach ($reports as $report)
                        @php($document = $stored[ReportArchive::key(ReportArchive::COLLECTION, $report->reference)] ?? null)
                        <tr data-report="{{ $report->reference }}" data-state="{{ $document !== null ? 'generado' : 'pendiente' }}">
                            <td class="font-semibold">{{ $report->reference }}</td>
                            <td class="min-w-40">{{ $report->seller }}</td>
                            <td data-align="end">{{ $report->transactions() }}</td>
                            <td data-align="end"><x-ui.money :value="$report->paidTotal()->amount()" /></td>
                            <td data-align="end"><x-ui.money :value="$report->expected->amount()" /></td>
                            <td data-align="end"><x-ui.money :value="$report->total()->amount()" class="font-semibold" /></td>
                            <td>
                                @if ($report->matches())
                                    <x-ui.chip tone="success">{{ __('comisiones::informes.recaudo.cuadra') }}</x-ui.chip>
                                @else
                                    <x-ui.chip tone="danger">{{ __('comisiones::informes.recaudo.diferencia', ['valor' => Money::format($report->difference()->amount())]) }}</x-ui.chip>
                                @endif
                            </td>
                            <td class="min-w-56">
                                @if ($document !== null)
                                    <p>{{ __('comisiones::informes.recaudo.generado', ['fecha' => DateFormat::dateTime($document->created_at), 'nombre' => $document->generator?->name ?? AuditTrail::SYSTEM]) }}</p>
                                    <p class="text-sm text-fg-muted [overflow-wrap:anywhere]">{{ $document->archivo }} · {{ __('comisiones::informes.recaudo.huella', ['huella' => substr($document->sha256, 0, 12)]) }}</p>
                                @else
                                    <x-ui.chip tone="neutral">{{ __('comisiones::informes.recaudo.pendiente') }}</x-ui.chip>
                                @endif
                            </td>
                            <td data-align="end">
                                @if ($document !== null)
                                    <x-ui.link-button :href="route('comisiones.periodos.informes.pdf', [...$query, 'referencia' => $report->reference])" variant="ghost" icon="download"
                                        aria-label="{{ __('comisiones::informes.acciones.descargar_de', ['referencia' => $report->reference]) }}">{{ __('comisiones::informes.acciones.descargar') }}</x-ui.link-button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            @php($payroll = $stored[ReportArchive::key(ReportArchive::PAYROLL, ReportArchive::PAYROLL)] ?? null)
            <x-ui.card :title="__('comisiones::informes.nomina.titulo_tarjeta')" :description="__('comisiones::informes.nomina.descripcion')" data-document="nomina">
                @if ($payroll !== null)
                    <x-slot:actions>
                        <x-ui.link-button :href="route('comisiones.periodos.informes.nomina', $query)" variant="secondary" icon="download">{{ __('comisiones::informes.nomina.descargar') }}</x-ui.link-button>
                    </x-slot:actions>
                @endif
                <div class="text-[0.9375rem]">
                    @if ($payroll !== null)
                        <p>{{ __('comisiones::informes.recaudo.generado', ['fecha' => DateFormat::dateTime($payroll->created_at), 'nombre' => $payroll->generator?->name ?? AuditTrail::SYSTEM]) }}</p>
                        <p class="text-sm text-fg-muted [overflow-wrap:anywhere]">{{ $payroll->archivo }} · {{ __('comisiones::informes.recaudo.huella', ['huella' => substr($payroll->sha256, 0, 12)]) }}</p>
                    @else
                        <x-ui.chip tone="neutral">{{ __('comisiones::informes.recaudo.pendiente') }}</x-ui.chip>
                    @endif
                </div>
            </x-ui.card>
        </div>
    @endif
</x-layouts.app>
