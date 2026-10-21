@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /comisiones/periodos/{periodo}/codigos/{codigo} (comisiones.periodos.codigo):
    the settlement of one code (§7.5). $selected: SelectedSettlement;
    $code: CodeSettlement; $tranches: TrancheTable of the period; $filters:
    LineFilters; $sales / $collections: the lines left by the filters. The
    figures of the code always come from $code, never from the filtered lines.
--}}
@php
    $rule = $code->rule;
    $version = $selected->versionQuery();
    $query = $version === null ? [] : ['version' => $version];
    $title = __('comisiones::codigo.titulo', ['codigo' => $code->code(), 'nombre' => $rule->name]);
    $back = Route::has('comisiones.periodos.consolidado')
        ? [route('comisiones.periodos.consolidado', ['periodo' => $period->key(), ...$query]), __('comisiones::codigo.volver')]
        : [route('comisiones.periodos.show', ['periodo' => $period->key(), ...$query]), __('comisiones::codigo.volver_resumen')];
    $minimum = Labels::rate($rule->minimumCompliance);
    $complianceHint = match (true) {
        $rule->minimumCompliance->isZero() => __('comisiones::codigo.cifras.sin_minimo'),
        $code->meetsMinimum => __('comisiones::codigo.cifras.cumple', ['minimo' => $minimum]),
        default => __('comisiones::codigo.cifras.no_cumple', ['minimo' => $minimum]),
    };

    $saleColumns = [
        ['key' => 'documento', 'label' => __('comisiones::codigo.ventas.columnas.documento')],
        ['key' => 'tipo', 'label' => __('comisiones::codigo.ventas.columnas.tipo')],
        ['key' => 'fecha', 'label' => __('comisiones::codigo.ventas.columnas.fecha')],
        ['key' => 'cliente', 'label' => __('comisiones::codigo.ventas.columnas.cliente')],
        ['key' => 'total_sap', 'label' => __('comisiones::codigo.ventas.columnas.total_sap'), 'align' => 'end'],
        ['key' => 'venta', 'label' => __('comisiones::codigo.ventas.columnas.venta'), 'align' => 'end'],
    ];
    $collectionColumns = [
        ['key' => 'factura', 'label' => __('comisiones::codigo.recaudos.columnas.factura')],
        ['key' => 'cliente', 'label' => __('comisiones::codigo.recaudos.columnas.cliente')],
        ['key' => 'fecha_factura', 'label' => __('comisiones::codigo.recaudos.columnas.fecha_factura')],
        ['key' => 'fecha_pago', 'label' => __('comisiones::codigo.recaudos.columnas.fecha_pago')],
        ['key' => 'importe', 'label' => __('comisiones::codigo.recaudos.columnas.importe'), 'align' => 'end'],
        ['key' => 'dias', 'label' => __('comisiones::codigo.recaudos.columnas.dias'), 'align' => 'end'],
        ['key' => 'tramo', 'label' => __('comisiones::codigo.recaudos.columnas.tramo'), 'align' => 'end'],
        ['key' => 'pagado', 'label' => __('comisiones::codigo.recaudos.columnas.pagado'), 'align' => 'end'],
        ['key' => 'comision', 'label' => __('comisiones::codigo.recaudos.columnas.comision'), 'align' => 'end'],
    ];
    $trancheColumns = [
        ['key' => 'dias', 'label' => __('comisiones::codigo.regla.columnas.dias')],
        ['key' => 'porcentaje', 'label' => __('comisiones::codigo.regla.columnas.porcentaje'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="$title">
    <x-comisiones::period-header :period="$period" :title="$title" :current="null" :version="$version">
        <x-slot:actions>
            <x-ui.link-button :href="$back[0]" variant="ghost" icon="chevron-left">{{ $back[1] }}</x-ui.link-button>
        </x-slot:actions>
    </x-comisiones::period-header>

    <div class="flex flex-col gap-6">
        @unless ($selected->isLatest())
            <x-ui.alert tone="info">{{ __('comisiones::comun.viendo_version', ['version' => $selected->settlement->version, 'vigente' => $selected->latestVersion]) }}</x-ui.alert>
        @endunless

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat :label="__('comisiones::codigo.cifras.venta')"><x-ui.money :value="$code->sales->amount()" /></x-ui.stat>
            <x-ui.stat :label="__('comisiones::codigo.cifras.presupuesto')" :value="$code->budget === null ? __('comisiones::codigo.cifras.sin_presupuesto') : null">
                @if ($code->budget !== null)
                    <x-ui.money :value="$code->budget->amount()" />
                @endif
            </x-ui.stat>
            <x-ui.stat :label="__('comisiones::codigo.cifras.cumplimiento')" :value="Labels::percent($code->compliance)" :hint="$complianceHint"
                :tone="$rule->minimumCompliance->isZero() ? 'neutral' : ($code->meetsMinimum ? 'success' : 'warning')" />
            <x-ui.stat :label="__('comisiones::codigo.cifras.comision_venta')"><x-ui.money :value="$code->saleCommission->amount()" /></x-ui.stat>
            <x-ui.stat :label="__('comisiones::codigo.cifras.recaudo')" :hint="trans_choice('comisiones::codigo.cifras.transacciones', $code->transactions(), ['cantidad' => $code->transactions()])">
                <x-ui.money :value="$code->collected->amount()" />
            </x-ui.stat>
            <x-ui.stat :label="__('comisiones::codigo.cifras.comision_recaudo')"><x-ui.money :value="$code->collectionCommission->amount()" /></x-ui.stat>
            <x-ui.stat :label="__('comisiones::codigo.capturas.total_obr')"><x-ui.money :value="$code->obrCommission->amount()" /></x-ui.stat>
            <x-ui.stat :label="__('comisiones::codigo.cifras.total')" :hint="__('comisiones::codigo.cifras.total_ayuda')"><x-ui.money :value="$code->total->amount()" /></x-ui.stat>
        </div>

        <x-ui.card :title="__('comisiones::codigo.regla.titulo')">
            <div class="grid gap-6 lg:grid-cols-2">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-[0.9375rem]">
                    <div>
                        <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::codigo.regla.tasa_venta') }}</dt>
                        <dd>{{ Labels::rate($rule->saleRate) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::codigo.regla.tasa_recaudo') }}</dt>
                        <dd>{{ Labels::rate($rule->collectionRate) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::codigo.regla.minimo') }}</dt>
                        <dd>{{ $minimum }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::codigo.regla.alcance') }}</dt>
                        <dd>{{ $rule->scope->label() }}</dd>
                    </div>
                </dl>

                <div>
                    <h3 class="mb-2 text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::codigo.regla.tramos') }}</h3>
                    @if ($rule->usesTranches)
                        <x-ui.table :caption="__('comisiones::codigo.regla.tabla_tramos')" :caption-hidden="true" :columns="$trancheColumns">
                            @foreach ($tranches->tranches as $tranche)
                                <tr>
                                    <td>{{ $tranche->toDays === null
                                        ? __('comisiones::codigo.regla.tramo_abierto', ['desde' => $tranche->fromDays])
                                        : __('comisiones::codigo.regla.tramo', ['desde' => $tranche->fromDays, 'hasta' => $tranche->toDays]) }}</td>
                                    <td data-align="end">{{ Labels::rate($tranche->share) }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @else
                        <p class="text-[0.9375rem]">{{ __('comisiones::codigo.regla.sin_tramos') }}</p>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <form method="GET" action="{{ route('comisiones.periodos.codigo', ['periodo' => $period->key(), 'codigo' => $code->code()]) }}" role="search" aria-label="{{ __('comisiones::codigo.filtros.etiqueta') }}" class="flex flex-wrap items-end gap-3">
            @if ($version !== null)
                <input type="hidden" name="version" value="{{ $version }}">
            @endif
            <div class="min-w-[12rem] flex-1">
                <x-ui.input name="cliente" type="search" :label="__('comisiones::codigo.filtros.cliente')" :hint="__('comisiones::codigo.filtros.cliente_ayuda')" :value="$filters->client" />
            </div>
            <div class="min-w-[9rem]">
                <x-ui.input name="factura" :label="__('comisiones::codigo.filtros.factura')" :value="$filters->invoice" />
            </div>
            <div class="min-w-[10rem]">
                <x-ui.input name="desde" type="date" :label="__('comisiones::codigo.filtros.desde')" :value="$filters->from" />
            </div>
            <div class="min-w-[10rem]">
                <x-ui.input name="hasta" type="date" :label="__('comisiones::codigo.filtros.hasta')" :value="$filters->to" />
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter">{{ __('comisiones::codigo.filtros.aplicar') }}</x-ui.button>
                @if ($filters->isActive())
                    <x-ui.link-button :href="route('comisiones.periodos.codigo', ['periodo' => $period->key(), 'codigo' => $code->code(), ...$query])" variant="ghost">{{ __('comisiones::codigo.filtros.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        @if ($filters->isActive())
            <x-ui.alert tone="info">{{ __('comisiones::codigo.filtros.nota') }}</x-ui.alert>
        @endif

        <x-ui.card :title="__('comisiones::codigo.recaudos.titulo')" :description="$filters->isActive() ? __('comisiones::codigo.recaudos.mostrando', ['mostradas' => count($collections), 'total' => count($code->collectionDetails)]) : null">
            <x-ui.table :caption="__('comisiones::codigo.recaudos.tabla')" :caption-hidden="true" :columns="$collectionColumns" :empty-message="__('comisiones::codigo.recaudos.vacio')">
                @foreach ($collections as $line)
                    <tr data-line="{{ $line->source->value }}">
                        <td>{{ $line->invoiceNumber }}</td>
                        <td class="min-w-48">
                            <span class="font-semibold">{{ $line->clientLabel() }}</span>
                            <span class="block text-sm">{{ $line->clientCode }}</span>
                        </td>
                        <td><x-ui.date :value="Labels::isoDay($line->invoiceDate)" /></td>
                        <td><x-ui.date :value="Labels::isoDay($line->paymentDate)" /></td>
                        <td data-align="end"><x-ui.money :value="$line->invoiceAmount->amount()" /></td>
                        <td data-align="end">{{ $line->days }}</td>
                        <td data-align="end">{{ $line->tranched ? Labels::rate($line->share) : __('comisiones::codigo.recaudos.sin_tramo') }}</td>
                        <td data-align="end"><x-ui.money :value="$line->paidBase->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$line->commission->amount()" /></td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <tr>
                        <th scope="row" colspan="7">{{ __('comisiones::codigo.recaudos.total') }}</th>
                        <td data-align="end"><x-ui.money :value="$code->collected->amount()" class="font-semibold" /></td>
                        <td data-align="end"><x-ui.money :value="$code->collectionCommission->amount()" class="font-semibold" /></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('comisiones::codigo.ventas.titulo')" :description="$filters->isActive() ? __('comisiones::codigo.ventas.mostrando', ['mostradas' => count($sales), 'total' => count($code->saleDetails)]) : null">
            <x-ui.table :caption="__('comisiones::codigo.ventas.tabla')" :caption-hidden="true" :columns="$saleColumns" :empty-message="__('comisiones::codigo.ventas.vacio')">
                @foreach ($sales as $line)
                    <tr data-line="venta">
                        <td>{{ $line->documentNumber }}</td>
                        <td>{{ $line->documentType->label() }}</td>
                        <td><x-ui.date :value="Labels::isoDay($line->documentDate)" /></td>
                        <td class="min-w-48">
                            <span class="font-semibold">{{ $line->clientName }}</span>
                            <span class="block text-sm">{{ $line->clientCode }}</span>
                        </td>
                        <td data-align="end"><x-ui.money :value="$line->total->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$line->amount->amount()" /></td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <tr>
                        <th scope="row" colspan="5">{{ __('comisiones::codigo.ventas.total') }}</th>
                        <td data-align="end"><x-ui.money :value="$code->sales->amount()" class="font-semibold" /></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>

        @if ($code->obrSales !== [] || $code->adjustments !== [])
            <div class="grid gap-6 lg:grid-cols-2">
                @if ($code->obrSales !== [])
                    <x-ui.card :title="__('comisiones::codigo.capturas.obr')">
                        <x-ui.table :caption="__('comisiones::codigo.capturas.tabla_obr')" :caption-hidden="true" :columns="[
                            ['key' => 'descripcion', 'label' => __('comisiones::codigo.capturas.columnas.descripcion')],
                            ['key' => 'vendido', 'label' => __('comisiones::codigo.capturas.columnas.vendido'), 'align' => 'end'],
                            ['key' => 'tasa', 'label' => __('comisiones::codigo.capturas.columnas.tasa'), 'align' => 'end'],
                        ]">
                            @foreach ($code->obrSales as $obr)
                                <tr data-line="obr">
                                    <td class="min-w-40 [overflow-wrap:anywhere]">{{ $obr->description }}</td>
                                    <td data-align="end"><x-ui.money :value="$obr->soldValue->amount()" /></td>
                                    <td data-align="end">{{ Labels::rate($obr->rate) }}</td>
                                </tr>
                            @endforeach

                            <x-slot:footer>
                                <tr>
                                    <th scope="row" colspan="2">{{ __('comisiones::codigo.capturas.total_obr') }}</th>
                                    <td data-align="end"><x-ui.money :value="$code->obrCommission->amount()" class="font-semibold" /></td>
                                </tr>
                            </x-slot:footer>
                        </x-ui.table>
                    </x-ui.card>
                @endif

                @if ($code->adjustments !== [])
                    <x-ui.card :title="__('comisiones::codigo.capturas.ajustes')">
                        <x-ui.table :caption="__('comisiones::codigo.capturas.tabla_ajustes')" :caption-hidden="true" :columns="[
                            ['key' => 'tipo', 'label' => __('comisiones::codigo.capturas.columnas.tipo')],
                            ['key' => 'descripcion', 'label' => __('comisiones::codigo.capturas.columnas.descripcion')],
                            ['key' => 'valor', 'label' => __('comisiones::codigo.capturas.columnas.valor'), 'align' => 'end'],
                        ]">
                            @foreach ($code->adjustments as $adjustment)
                                <tr data-line="ajuste">
                                    <td>{{ $adjustment->kind->label() }}</td>
                                    <td class="min-w-40 [overflow-wrap:anywhere]">{{ $adjustment->description }}</td>
                                    <td data-align="end"><x-ui.money :value="$adjustment->amount->amount()" /></td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>
