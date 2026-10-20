@use('App\Modules\Comisiones\Domain\Settlement\SettlementStatus')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')

{{--
    GET /comisiones/periodos/{periodo}/consolidado (comisiones.periodos.consolidado):
    the consolidated report (§8.1). $selected: SelectedSettlement; $report:
    ConsolidatedReport, the same rows the Excel and PDF files hold. Every code
    links to its settlement (drill-down, §2.3).
--}}
@php
    $settlement = $report->settlement;
    $version = $selected->versionQuery();
    $query = ['periodo' => $period->key(), ...($version === null ? [] : ['version' => $version])];
    $title = __('comisiones::consolidado.titulo_pagina', ['mes' => Labels::month($period)]);

    $regularColumns = [];
    foreach (['codigo', 'vendedor', 'venta', 'recaudo', 'obr', 'reintegros', 'descuentos', ...($report->hasDirectorColumn ? ['director'] : []), 'total'] as $column) {
        $regularColumns[] = [
            'key' => $column,
            'label' => __('comisiones::consolidado.regular.columnas.'.$column),
            'align' => in_array($column, ['codigo', 'vendedor'], true) ? 'start' : 'end',
        ];
    }

    $separateColumns = [
        ['key' => 'codigo', 'label' => __('comisiones::consolidado.especiales.columnas.codigo')],
        ['key' => 'nombre', 'label' => __('comisiones::consolidado.especiales.columnas.nombre')],
        ['key' => 'venta', 'label' => __('comisiones::consolidado.especiales.columnas.venta'), 'align' => 'end'],
        ['key' => 'comision', 'label' => __('comisiones::consolidado.especiales.columnas.comision'), 'align' => 'end'],
        ['key' => 'total', 'label' => __('comisiones::consolidado.especiales.columnas.total'), 'align' => 'end'],
    ];
    $teamColumns = [
        ['key' => 'codigo', 'label' => __('comisiones::consolidado.directores.columnas.codigo')],
        ['key' => 'nombre', 'label' => __('comisiones::consolidado.directores.columnas.nombre')],
        ['key' => 'recaudo', 'label' => __('comisiones::consolidado.directores.columnas.recaudo'), 'align' => 'end'],
        ['key' => 'venta', 'label' => __('comisiones::consolidado.directores.columnas.venta'), 'align' => 'end'],
    ];
    $personColumns = [
        ['key' => 'persona', 'label' => __('comisiones::consolidado.personas.columnas.persona')],
        ['key' => 'roles', 'label' => __('comisiones::consolidado.personas.columnas.roles')],
        ['key' => 'total', 'label' => __('comisiones::consolidado.personas.columnas.total'), 'align' => 'end'],
    ];
    $codeLink = fn (int $code): string => route('comisiones.periodos.codigo', [...$query, 'codigo' => $code]);
    $linkClass = 'font-semibold text-brand-ink underline-offset-4 hover:underline';
@endphp

<x-layouts.app :title="$title">
    <x-comisiones::period-header :period="$period" :title="$title" current="consolidado" :version="$version" :description="$report->title">
        <x-slot:actions>
            <x-ui.link-button :href="route('comisiones.periodos.consolidado.xlsx', $query)" variant="secondary" icon="download">{{ __('comisiones::consolidado.descargar_excel') }}</x-ui.link-button>
            <x-ui.link-button :href="route('comisiones.periodos.consolidado.pdf', $query)" variant="secondary" icon="download">{{ __('comisiones::consolidado.descargar_pdf') }}</x-ui.link-button>
        </x-slot:actions>
    </x-comisiones::period-header>

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.chip :tone="Labels::statusTone($settlement->status)">{{ $report->status }}</x-ui.chip>
            <span class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::comun.version_revision', ['version' => $settlement->version, 'revision' => $settlement->revision]) }}</span>
        </div>

        @if ($settlement->status !== SettlementStatus::Closed)
            <x-ui.alert tone="warning">{{ __('comisiones::consolidado.no_cerrada') }}</x-ui.alert>
        @endif

        <x-ui.card :title="__('comisiones::consolidado.regular.titulo')">
            <x-ui.table :caption="__('comisiones::consolidado.regular.tabla')" :caption-hidden="true" :columns="$regularColumns">
                @foreach ($report->regular as $row)
                    <tr data-row="{{ $row->reference }}">
                        <td>
                            @if ($row->code !== null)
                                <a href="{{ $codeLink($row->code) }}" class="{{ $linkClass }}" aria-label="{{ __('comisiones::consolidado.regular.ver_codigo', ['codigo' => $row->code]) }}">{{ $row->reference }}</a>
                            @else
                                {{ $row->reference }}
                            @endif
                        </td>
                        <td class="min-w-44">{{ $row->name }}</td>
                        <td data-align="end"><x-ui.money :value="$row->saleCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$row->collectionCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$row->obrCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$row->reimbursements->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$row->deductions->amount()" /></td>
                        @if ($report->hasDirectorColumn)
                            <td data-align="end"><x-ui.money :value="$row->director->amount()" /></td>
                        @endif
                        <td data-align="end"><x-ui.money :value="$row->total->amount()" class="font-semibold" /></td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    @php($total = $report->regularTotal)
                    <tr data-row="total">
                        <th scope="row" colspan="2">{{ $total->name }}</th>
                        <td data-align="end"><x-ui.money :value="$total->saleCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$total->collectionCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$total->obrCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$total->reimbursements->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$total->deductions->amount()" /></td>
                        @if ($report->hasDirectorColumn)
                            <td data-align="end"><x-ui.money :value="$total->director->amount()" /></td>
                        @endif
                        <td data-align="end"><x-ui.money :value="$total->total->amount()" class="font-semibold" /></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('comisiones::consolidado.especiales.titulo')">
            <x-ui.table :caption="__('comisiones::consolidado.especiales.tabla')" :caption-hidden="true" :columns="$separateColumns">
                @foreach ($report->separate as $code)
                    <tr data-separate="{{ $code->code() }}">
                        <td><a href="{{ $codeLink($code->code()) }}" class="{{ $linkClass }}" aria-label="{{ __('comisiones::consolidado.regular.ver_codigo', ['codigo' => $code->code()]) }}">{{ $code->code() }}</a></td>
                        <td class="min-w-44">{{ $code->rule->name }}</td>
                        <td data-align="end"><x-ui.money :value="$code->sales->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$code->saleCommission->amount()" /></td>
                        <td data-align="end"><x-ui.money :value="$code->total->amount()" class="font-semibold" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <section aria-labelledby="directores-titulo" class="flex flex-col gap-4">
            <h2 id="directores-titulo" class="text-lg leading-snug font-bold">{{ __('comisiones::consolidado.directores.titulo') }}</h2>

            <div class="grid gap-6 xl:grid-cols-2">
                @foreach ($report->directors as $director)
                    @php($team = $director->team)
                    <x-ui.card :level="3" data-team="{{ $team->key }}"
                        :title="__('comisiones::consolidado.directores.equipo', ['canal' => $team->channel, 'director' => $team->director->name])"
                        :description="__('comisiones::consolidado.directores.tasa', ['tasa' => Labels::rate($team->rate)]).($director->paidWithCode !== null ? ' '.__('comisiones::consolidado.directores.con_codigo', ['codigo' => $director->paidWithCode]) : '')">
                        <x-ui.table :caption="__('comisiones::consolidado.directores.tabla', ['canal' => $team->channel])" :caption-hidden="true" :columns="$teamColumns">
                            @foreach ($director->rows as $member)
                                <tr>
                                    <td><a href="{{ $codeLink($member->code) }}" class="{{ $linkClass }}" aria-label="{{ __('comisiones::consolidado.regular.ver_codigo', ['codigo' => $member->code]) }}">{{ $member->code }}</a></td>
                                    <td class="min-w-36">{{ $member->name }}</td>
                                    <td data-align="end"><x-ui.money :value="$member->collected->amount()" /></td>
                                    <td data-align="end"><x-ui.money :value="$member->sales->amount()" /></td>
                                </tr>
                            @endforeach

                            <x-slot:footer>
                                <tr>
                                    <th scope="row" colspan="2">{{ __('comisiones::consolidado.directores.total') }}</th>
                                    <td data-align="end"><x-ui.money :value="$director->collected->amount()" /></td>
                                    <td data-align="end"><x-ui.money :value="$director->sales->amount()" /></td>
                                </tr>
                                <tr>
                                    <th scope="row" colspan="2">{{ __('comisiones::consolidado.directores.comision') }}</th>
                                    <td data-align="end"><x-ui.money :value="$director->collectionCommission->amount()" /></td>
                                    <td data-align="end"><x-ui.money :value="$director->salesCommission->amount()" /></td>
                                </tr>
                                <tr>
                                    <th scope="row" colspan="3">{{ __('comisiones::consolidado.directores.nomina') }}</th>
                                    <td data-align="end"><x-ui.money :value="$director->total->amount()" class="font-semibold" /></td>
                                </tr>
                            </x-slot:footer>
                        </x-ui.table>
                    </x-ui.card>
                @endforeach
            </div>
        </section>

        <x-ui.card :title="__('comisiones::consolidado.personas.titulo')">
            <x-ui.table :caption="__('comisiones::consolidado.personas.tabla')" :caption-hidden="true" :columns="$personColumns">
                @foreach ($report->persons as $person)
                    <tr data-person="{{ $person->person->id }}">
                        <td class="min-w-40 font-semibold">{{ $person->person->name }}</td>
                        <td class="min-w-64">
                            <ul class="flex flex-col gap-0.5">
                                @foreach ($person->roles as $role)
                                    <li>{{ $role->label }}: <x-ui.money :value="$role->amount->amount()" /></li>
                                @endforeach
                            </ul>
                        </td>
                        <td data-align="end"><x-ui.money :value="$person->total->amount()" class="font-semibold" /></td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <tr>
                        <th scope="row" colspan="2">{{ __('comisiones::consolidado.personas.total') }}</th>
                        <td data-align="end"><x-ui.money :value="$report->payrollTotal->amount()" class="font-semibold" /></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    </div>
</x-layouts.app>
