@use('App\Modules\Comisiones\Domain\Settlement\SettlementStatus')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /comisiones (comisiones.tablero): the dashboard of a period (§7.1).
    $period; $result: SettlementResult of the live preview, null when
    $missingParameters; $codes: CodeSettlement listed (regular and separate
    codes with activity); $chart: ComplianceBars::of(); $loads:
    list<LoadStatusRow>; $pending: pending captures per kind; $settlement:
    the latest SettlementRecord of the period, or null.
--}}
@php
    $title = __('comisiones::tablero.titulo');
    $blocking = $result === null ? 0 : count($result->blockingAlerts());
    $warnings = $result === null ? 0 : count($result->alerts()) - $blocking;
    $pendingTotal = array_sum($pending);
    $status = $settlement === null ? null : SettlementStatus::from($settlement->estado);
    $nothingLoaded = collect($loads)->every(fn ($row) => $row->last === null && $row->rejected === []);

    $codeColumns = [
        ['key' => 'codigo', 'label' => __('comisiones::tablero.codigos.columnas.codigo')],
        ['key' => 'vendedor', 'label' => __('comisiones::tablero.codigos.columnas.vendedor')],
        ['key' => 'venta', 'label' => __('comisiones::tablero.codigos.columnas.venta'), 'align' => 'end'],
        ['key' => 'presupuesto', 'label' => __('comisiones::tablero.codigos.columnas.presupuesto'), 'align' => 'end'],
        ['key' => 'cumplimiento', 'label' => __('comisiones::tablero.codigos.columnas.cumplimiento'), 'align' => 'end'],
        ['key' => 'minimo', 'label' => __('comisiones::tablero.codigos.columnas.minimo'), 'align' => 'end'],
        ['key' => 'comision_venta', 'label' => __('comisiones::tablero.codigos.columnas.comision_venta'), 'align' => 'end'],
        ['key' => 'recaudo', 'label' => __('comisiones::tablero.codigos.columnas.recaudo'), 'align' => 'end'],
        ['key' => 'comision_recaudo', 'label' => __('comisiones::tablero.codigos.columnas.comision_recaudo'), 'align' => 'end'],
    ];
    $loadColumns = [
        ['key' => 'archivo', 'label' => __('comisiones::tablero.cargas.columnas.archivo')],
        ['key' => 'recibida', 'label' => __('comisiones::tablero.cargas.columnas.recibida')],
        ['key' => 'corte', 'label' => __('comisiones::tablero.cargas.columnas.corte')],
        ['key' => 'filas', 'label' => __('comisiones::tablero.cargas.columnas.filas'), 'align' => 'end'],
        ['key' => 'errores', 'label' => __('comisiones::tablero.cargas.columnas.errores')],
    ];
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('comisiones::comun.contexto_periodo', ['mes' => Labels::month($period)])" :description="__('comisiones::tablero.descripcion')">
        <x-slot:actions>
            <form method="GET" action="{{ route('comisiones.tablero') }}" aria-label="{{ __('comisiones::tablero.periodo.etiqueta') }}" class="flex flex-wrap items-end gap-2">
                <x-ui.input name="periodo" type="month" :label="__('comisiones::tablero.periodo.campo')" :value="$period->key()" />
                <x-ui.button type="submit" variant="secondary">{{ __('comisiones::tablero.periodo.ver') }}</x-ui.button>
            </form>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if ($missingParameters)
            <x-ui.alert tone="danger">{{ __('comisiones::tablero.sin_parametros', ['periodo' => $period->key()]) }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-5">
            <x-ui.stat :label="__('comisiones::tablero.kpis.liquidacion')" data-stat="liquidacion"
                :href="route('comisiones.periodos.show', $period->key())"
                :hint="$settlement === null ? __('comisiones::tablero.kpis.ver_liquidacion') : __('comisiones::comun.version_revision', ['version' => $settlement->version, 'revision' => $settlement->revision])">
                @if ($status === null)
                    {{ __('comisiones::tablero.kpis.sin_calcular') }}
                @else
                    <x-ui.chip :tone="Labels::statusTone($status)">{{ Labels::status($status) }}</x-ui.chip>
                @endif
            </x-ui.stat>
            <x-ui.stat :label="__('comisiones::tablero.kpis.venta')" :hint="__('comisiones::tablero.kpis.venta_ayuda')">
                <x-ui.money :value="$result?->controls()->loadedSales->amount()" />
            </x-ui.stat>
            <x-ui.stat :label="__('comisiones::tablero.kpis.recaudo')" :hint="__('comisiones::tablero.kpis.recaudo_ayuda')">
                <x-ui.money :value="$result?->controls()->loadedCollections->amount()" />
            </x-ui.stat>
            <x-ui.stat :label="__('comisiones::tablero.kpis.bloqueantes')" :value="(string) $blocking" data-stat="bloqueantes" data-value="{{ $blocking }}"
                :hint="trans_choice('comisiones::tablero.kpis.advertencias', $warnings, ['cantidad' => $warnings])"
                :tone="$blocking > 0 ? 'danger' : 'neutral'" />
            <x-ui.stat :label="__('comisiones::tablero.kpis.pendientes')" :value="(string) $pendingTotal" data-stat="pendientes" data-value="{{ $pendingTotal }}"
                :hint="__('comisiones::tablero.kpis.pendientes_detalle', $pending)"
                :tone="$pendingTotal > 0 ? 'warning' : 'neutral'" />
        </div>

        @if ($result !== null)
            <x-ui.card :title="__('comisiones::tablero.avance.titulo')" :description="__('comisiones::tablero.avance.descripcion')">
                <x-comisiones::compliance-bars :chart="$chart" table-id="acumulado-por-codigo" />
            </x-ui.card>

            <x-ui.card id="acumulado-por-codigo" :title="__('comisiones::tablero.codigos.titulo')">
                <x-ui.table :caption="__('comisiones::tablero.codigos.tabla')" :caption-hidden="true" :columns="$codeColumns" :empty-message="__('comisiones::tablero.codigos.vacio')">
                    @foreach ($codes as $code)
                        <tr data-code="{{ $code->code() }}">
                            <td>{{ $code->code() }}</td>
                            <td class="min-w-40">{{ $code->rule->name }}</td>
                            <td data-align="end"><x-ui.money :value="$code->sales->amount()" /></td>
                            <td data-align="end">
                                @if ($code->budget === null)
                                    {{ __('comisiones::tablero.codigos.sin_presupuesto') }}
                                @else
                                    <x-ui.money :value="$code->budget->amount()" />
                                @endif
                            </td>
                            <td data-align="end">{{ $code->hasBudget() ? Labels::percent($code->compliance) : '—' }}</td>
                            <td data-align="end">{{ Labels::rate($code->rule->minimumCompliance) }}</td>
                            <td data-align="end"><x-ui.money :value="$code->saleCommission->amount()" /></td>
                            <td data-align="end"><x-ui.money :value="$code->collected->amount()" /></td>
                            <td data-align="end"><x-ui.money :value="$code->collectionCommission->amount()" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('comisiones::tablero.cargas.titulo')" :description="__('comisiones::tablero.cargas.descripcion')">
            @if ($nothingLoaded)
                <x-ui.empty icon="upload" :title="__('comisiones::tablero.cargas.nada')" />
            @else
                <x-ui.table :caption="__('comisiones::tablero.cargas.tabla')" :caption-hidden="true" :columns="$loadColumns">
                    @foreach ($loads as $row)
                        <tr data-dataset="{{ $row->dataset->value }}">
                            <td class="font-semibold">{{ $row->dataset->label() }}</td>
                            @if ($row->last === null)
                                <td colspan="3">{{ __('comisiones::tablero.cargas.sin_cargas') }}</td>
                            @else
                                <td><x-ui.date :value="$row->last->recibida_en" :time="true" /></td>
                                <td><x-ui.date :value="$row->last->corte->format('Y-m-d')" /></td>
                                <td data-align="end">
                                    {{ number_format($row->last->filas, 0, ',', '.') }}
                                    <span class="block text-sm text-fg-muted">{{ __('comisiones::tablero.cargas.filas_detalle', ['insertadas' => number_format($row->last->insertadas, 0, ',', '.'), 'actualizadas' => number_format($row->last->actualizadas, 0, ',', '.')]) }}</span>
                                </td>
                            @endif
                            <td>
                                @if ($row->rejected === [])
                                    <x-ui.chip tone="success">{{ __('comisiones::tablero.cargas.sin_errores') }}</x-ui.chip>
                                @else
                                    <x-ui.chip tone="danger">{{ trans_choice('comisiones::tablero.cargas.rechazadas', count($row->rejected), ['cantidad' => count($row->rejected)]) }}</x-ui.chip>
                                    <span class="block text-sm text-fg-muted">{{ __('comisiones::tablero.cargas.ultima_rechazada', ['fecha' => DateFormat::dateTime($row->rejected[0]->receivedAt)]) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
