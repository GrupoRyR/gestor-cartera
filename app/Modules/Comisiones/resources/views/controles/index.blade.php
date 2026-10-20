@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Modules\Core\Contracts\Enums\Dataset')
@use('App\Support\Formatting\DateFormat')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /comisiones/periodos/{periodo}/controles (comisiones.periodos.controles):
    the controls of §9 for the version shown (§7.8). $selected: SelectedSettlement;
    $controls: ControlsReport, null for a period never calculated. Each control
    is a card with data-control (its §9 number) and data-status (cumple,
    no_cumple, no_aplica), its status chip and its figures.
--}}
@php
    $settlement = $selected->settlement;
    $version = $selected->versionQuery();
    $query = ['periodo' => $period->key(), ...($version === null ? [] : ['version' => $version])];
    $title = __('comisiones::controles.titulo_pagina', ['mes' => Labels::month($period)]);
    $dt = 'text-[0.8125rem] font-semibold text-fg-muted';
    $dd = 'mt-0.5 text-lg font-semibold tabular-nums';
@endphp

<x-layouts.app :title="$title">
    <x-comisiones::period-header :period="$period" :title="$title" current="controles" :version="$version" :description="__('comisiones::controles.descripcion')" />

    @if ($controls === null)
        <x-ui.empty icon="chart" :title="__('comisiones::controles.sin_liquidacion')">
            <x-ui.link-button :href="route('comisiones.periodos.show', $period->key())" variant="secondary">{{ __('comisiones::controles.ir_al_periodo') }}</x-ui.link-button>
        </x-ui.empty>
    @else
        @php($totals = $controls->totals)
        <div class="flex flex-col gap-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.chip :tone="Labels::statusTone($settlement->status)">{{ Labels::status($settlement->status) }}</x-ui.chip>
                <span class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::comun.version_revision', ['version' => $settlement->version, 'revision' => $settlement->revision]) }}</span>
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                {{-- §9.1 --}}
                @php($status = $controls->sales())
                <x-ui.card :title="__('comisiones::controles.ventas.titulo')" :description="__('comisiones::controles.ventas.descripcion')" data-control="9.1" data-status="{{ $status->value }}">
                    <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.ventas.cargadas') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$totals->loadedSales->amount()" /></dd></div>
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.ventas.por_codigo') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$totals->salesByCode->amount()" /></dd></div>
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.ventas.sin_asignar') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$totals->unassignedSales->amount()" /></dd></div>
                    </dl>
                </x-ui.card>

                {{-- §9.2 --}}
                @php($status = $controls->collections())
                <x-ui.card :title="__('comisiones::controles.recaudos.titulo')" :description="__('comisiones::controles.recaudos.descripcion')" data-control="9.2" data-status="{{ $status->value }}">
                    <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.recaudos.cargados') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$totals->loadedCollections->amount()" /></dd></div>
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.recaudos.liquidados') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$totals->settledCollections->amount()" /></dd></div>
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.recaudos.sin_asignar') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$totals->unassignedCollections->amount()" /></dd></div>
                    </dl>
                </x-ui.card>

                {{-- §9.3 --}}
                @php($status = $controls->reportsReconcile())
                @php($mismatched = $controls->mismatchedReports())
                <x-ui.card :title="__('comisiones::controles.informes.titulo')" :description="__('comisiones::controles.informes.descripcion')" data-control="9.3" data-status="{{ $status->value }}">
                    <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.informes.total_pdf') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$controls->reportsTotal()->amount()" /></dd></div>
                        <div><dt class="{{ $dt }}">{{ __('comisiones::controles.informes.consolidado') }}</dt><dd class="{{ $dd }}"><x-ui.money :value="$controls->consolidatedCollections->amount()" /></dd></div>
                    </dl>
                    <div class="mt-4 flex flex-col gap-1 text-[0.9375rem] text-fg-soft">
                        @if ($mismatched !== [])
                            <p class="font-semibold text-fg">{{ __('comisiones::controles.informes.no_cuadran', ['referencias' => implode(', ', array_map(fn ($report) => $report->reference, $mismatched))]) }}</p>
                        @endif
                        <p>{{ __('comisiones::controles.informes.guardados', ['guardados' => $controls->storedReports, 'total' => count($controls->reports)]) }}</p>
                        <p class="text-sm text-fg-muted">{{ __('comisiones::controles.informes.origen') }}</p>
                    </div>
                    @if (Route::has('comisiones.periodos.informes.index') && auth()->user()?->can('comisiones.reports'))
                        <x-slot:footer>
                            <a href="{{ route('comisiones.periodos.informes.index', $query) }}" class="font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('comisiones::controles.informes.ver_informes') }}</a>
                        </x-slot:footer>
                    @endif
                </x-ui.card>

                {{-- §9.4 --}}
                @php($status = $controls->controlFile())
                <x-ui.card :title="__('comisiones::controles.control_sap.titulo')" :description="__('comisiones::controles.control_sap.descripcion')" data-control="9.4" data-status="{{ $status->value }}">
                    <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                    <p class="mb-3 text-sm text-fg-muted">{{ __('comisiones::controles.control_sap.rango', ['desde' => DateFormat::date($controls->loadsFrom), 'hasta' => DateFormat::date($controls->loadsTo)]) }}</p>
                    <x-ui.table :caption="__('comisiones::controles.control_sap.tabla')" :caption-hidden="true" :columns="[
                        ['key' => 'archivo', 'label' => __('comisiones::controles.control_sap.columnas.archivo')],
                        ['key' => 'cargas', 'label' => __('comisiones::controles.control_sap.columnas.cargas'), 'align' => 'end'],
                        ['key' => 'filas', 'label' => __('comisiones::controles.control_sap.columnas.filas'), 'align' => 'end'],
                        ['key' => 'detalle', 'label' => __('comisiones::controles.control_sap.columnas.detalle'), 'align' => 'end'],
                        ['key' => 'ultima', 'label' => __('comisiones::controles.control_sap.columnas.ultima')],
                    ]">
                        @foreach ($controls->loads as $load)
                            <tr data-load="{{ $load->dataset->value }}">
                                <td class="font-semibold">{{ $load->dataset->label() }}</td>
                                <td data-align="end">{{ number_format($load->loads, 0, ',', '.') }}</td>
                                <td data-align="end">{{ number_format($load->rows, 0, ',', '.') }}</td>
                                <td data-align="end">{{ number_format($load->inserted, 0, ',', '.') }} · {{ number_format($load->updated, 0, ',', '.') }}</td>
                                <td><x-ui.date :value="$load->lastReceived" :time="true" /></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>

                {{-- §9.5 --}}
                @php($status = $controls->idempotency())
                <x-ui.card :title="__('comisiones::controles.duplicados.titulo')" :description="__('comisiones::controles.duplicados.descripcion')" data-control="9.5" data-status="{{ $status->value }}">
                    <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                    <p class="mb-3 text-[0.9375rem] text-fg-soft">{{ trans_choice('comisiones::controles.duplicados.alertas', $controls->duplicates, ['cantidad' => $controls->duplicates]) }}</p>
                    <x-ui.table :caption="__('comisiones::controles.duplicados.tabla')" :caption-hidden="true" :columns="[
                        ['key' => 'archivo', 'label' => __('comisiones::controles.duplicados.columnas.archivo')],
                        ['key' => 'filas', 'label' => __('comisiones::controles.duplicados.columnas.filas'), 'align' => 'end'],
                    ]">
                        @foreach ($controls->staged as $dataset => $rows)
                            <tr data-staged="{{ $dataset }}">
                                <td class="font-semibold">{{ Dataset::from($dataset)->label() }}</td>
                                <td data-align="end">{{ number_format($rows, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        <tr data-staged="manual">
                            <td class="font-semibold">{{ __('comisiones::controles.duplicados.cruces_manuales') }}</td>
                            <td data-align="end">{{ number_format($controls->manualCrossings, 0, ',', '.') }}</td>
                        </tr>
                    </x-ui.table>
                </x-ui.card>

                {{-- §9.6 --}}
                @php($status = $controls->alerts())
                <x-ui.card :title="__('comisiones::controles.alertas.titulo')" :description="__('comisiones::controles.alertas.descripcion')" data-control="9.6" data-status="{{ $status->value }}">
                    <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                    <x-ui.table :caption="__('comisiones::controles.alertas.tabla')" :caption-hidden="true" :empty-message="__('comisiones::controles.alertas.vacio')" :columns="[
                        ['key' => 'tipo', 'label' => __('comisiones::controles.alertas.columnas.tipo')],
                        ['key' => 'severidad', 'label' => __('comisiones::controles.alertas.columnas.severidad')],
                        ['key' => 'cantidad', 'label' => __('comisiones::controles.alertas.columnas.cantidad'), 'align' => 'end'],
                    ]">
                        @foreach ($controls->alertGroups as $group)
                            <tr data-alert-kind="{{ $group->kind->value }}">
                                <td class="min-w-48">{{ $group->kind->label() }}</td>
                                <td><x-ui.chip :tone="$group->isBlocking() ? 'danger' : 'warning'">{{ $group->kind->severity()->label() }}</x-ui.chip></td>
                                <td data-align="end">{{ number_format($group->count, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                    <dl class="mt-4">
                        <dt class="{{ $dt }}">{{ __('comisiones::controles.alertas.sin_fecha_factura') }}</dt>
                        <dd class="{{ $dd }}" data-without-invoice-date="{{ $controls->paymentsWithoutInvoiceDate }}">{{ number_format($controls->paymentsWithoutInvoiceDate, 0, ',', '.') }}</dd>
                        <dd class="text-sm text-fg-muted">{{ __('comisiones::controles.alertas.sin_fecha_factura_ayuda') }}</dd>
                    </dl>
                    <x-slot:footer>
                        <a href="{{ route('comisiones.periodos.show', $query) }}" class="font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('comisiones::controles.alertas.ver_periodo') }}</a>
                    </x-slot:footer>
                </x-ui.card>
            </div>

            {{-- §9.7 --}}
            @php($status = $controls->justifications())
            <x-ui.card :title="__('comisiones::controles.justificaciones.titulo')" :description="__('comisiones::controles.justificaciones.descripcion')" data-control="9.7" data-status="{{ $status->value }}">
                <x-slot:actions><x-ui.chip :tone="$status->tone()">{{ $status->label() }}</x-ui.chip></x-slot:actions>
                <x-ui.table :caption="__('comisiones::controles.justificaciones.tabla')" :caption-hidden="true" :empty-message="__('comisiones::controles.justificaciones.vacio')" :columns="[
                    ['key' => 'alerta', 'label' => __('comisiones::controles.justificaciones.columnas.alerta')],
                    ['key' => 'mensaje', 'label' => __('comisiones::controles.justificaciones.columnas.mensaje')],
                    ['key' => 'justificacion', 'label' => __('comisiones::controles.justificaciones.columnas.justificacion')],
                ]">
                    @foreach ($controls->blocking as $entry)
                        <tr data-alert="{{ $entry['alert']->key() }}">
                            <td class="min-w-44">{{ $entry['alert']->kind->label() }}: {{ $entry['alert']->reference }}</td>
                            <td class="min-w-64 [overflow-wrap:anywhere]">{{ $entry['alert']->message }}</td>
                            <td class="min-w-48 [overflow-wrap:anywhere]">
                                @if ($entry['justification'] === null)
                                    <x-ui.chip tone="danger">{{ __('comisiones::controles.justificaciones.sin_justificar') }}</x-ui.chip>
                                @else
                                    {{ $entry['justification'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    @endif
</x-layouts.app>
