@use('App\Modules\Bancos\Domain\Collections\MonthCellState')
@use('App\Modules\Core\Contracts\Enums\Bank')

{{--
    GET /bancos/recaudo (bancos.recaudo): the daily collections report and the
    month of that day as the grid the portfolio analyst keeps in her workbook.
    $date: the day of the report; $today: today (Bogotá); $day: DayReport;
    $month: MonthReport (one grid per company in view); $previousMonth and
    $nextMonth: the day the month links open, or null when out of range;
    $pending: credits of the day still to apply in SAP; $otherCurrency:
    credits of the day in a currency that does not add up.

    The grid is a table per company, like her sheets: a row per Monday-to-
    Friday week, the business days, a column per weekday and the totals. A
    weekday of another month is an empty shaded cell, a holiday says so, and
    a day that has not arrived reads "—" (not zero) with its reason for
    screen readers. A day with money links to its detail.
--}}
@php
    $long = fn ($value): string => $value->locale('es')->isoFormat('dddd D [de] MMMM');
    $short = fn ($value): string => $value->locale('es')->isoFormat('D [de] MMMM');
    $previousDay = $date->subDay();
    $nextDay = $date->addDay();

    $bankColumns = [
        ['key' => 'bank', 'label' => __('bancos::recaudo.web.columnas.banco')],
        ...array_map(fn ($company): array => ['key' => $company->value, 'label' => $company->value, 'align' => 'end'], count($day->companies) > 1 ? $day->companies : []),
        ['key' => 'total', 'label' => __('bancos::recaudo.web.columnas.total'), 'align' => 'end'],
        ['key' => 'share', 'label' => __('bancos::recaudo.web.columnas.participacion'), 'align' => 'end'],
    ];

    $monthColumns = [
        ['key' => 'week', 'label' => __('bancos::recaudo.web.mes.columnas.semana')],
        ['key' => 'days', 'label' => __('bancos::recaudo.web.mes.columnas.dias'), 'align' => 'center'],
        ...array_map(
            fn (string $weekday): array => ['key' => $weekday, 'label' => __("bancos::recaudo.web.mes.columnas.{$weekday}"), 'align' => 'end'],
            ['lunes', 'martes', 'miercoles', 'jueves', 'viernes'],
        ),
        ['key' => 'total', 'label' => __('bancos::recaudo.web.mes.columnas.total'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('bancos::recaudo.web.titulo')">
    <x-ui.page-header :title="__('bancos::recaudo.web.titulo')" :description="__('bancos::recaudo.web.descripcion')" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.link-button :href="route('bancos.recaudo', ['fecha' => $previousDay->format('Y-m-d')])" variant="secondary" icon="chevron-left">{{ __('bancos::recaudo.web.selector.anterior') }}</x-ui.link-button>

            <form method="GET" action="{{ route('bancos.recaudo') }}" aria-label="{{ __('bancos::recaudo.web.selector.etiqueta') }}" class="flex flex-wrap items-end gap-3">
                <x-ui.input name="fecha" type="date" :label="__('bancos::recaudo.web.selector.fecha')" :value="$date->format('Y-m-d')" :max="$today->format('Y-m-d')" />
                <x-ui.button type="submit" variant="secondary">{{ __('bancos::recaudo.web.selector.ver') }}</x-ui.button>
            </form>

            @if ($nextDay->lessThanOrEqualTo($today))
                <x-ui.link-button :href="route('bancos.recaudo', ['fecha' => $nextDay->format('Y-m-d')])" variant="secondary" icon="chevron-right">{{ __('bancos::recaudo.web.selector.siguiente') }}</x-ui.link-button>
            @endif

            @if ($date->toDateString() !== $today->subDay()->toDateString())
                <x-ui.link-button :href="route('bancos.recaudo')" variant="ghost">{{ __('bancos::recaudo.web.selector.ayer') }}</x-ui.link-button>
            @endif
        </div>

        @if ($otherCurrency > 0)
            <x-ui.alert tone="warning" :title="__('bancos::recaudo.web.otra_moneda.titulo')">
                {{ __('bancos::recaudo.web.otra_moneda.detalle', ['n' => $otherCurrency]) }}
            </x-ui.alert>
        @endif

        <x-ui.card :title="__('bancos::recaudo.web.dia.titulo', ['fecha' => $long($date)])">
            @if ($day->isEmpty())
                <x-ui.empty
                    icon="money"
                    :title="__('bancos::recaudo.web.vacio.titulo', ['fecha' => $short($date)])"
                    :description="$date->toDateString() === $today->toDateString() ? __('bancos::recaudo.web.vacio.detalle_hoy') : __('bancos::recaudo.web.vacio.detalle')"
                >
                    <x-ui.link-button :href="route('bancos.cargas.index')" variant="secondary">{{ __('bancos::recaudo.web.vacio.ver_cargas') }}</x-ui.link-button>
                </x-ui.empty>
            @else
                <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-3">
                    <x-ui.stat :label="__('bancos::recaudo.web.dia.total')">
                        <x-bancos::amount :value="$day->total" />
                    </x-ui.stat>
                    <x-ui.stat :label="__('bancos::recaudo.web.dia.movimientos')" :value="number_format($day->movementCount, 0, ',', '.')" />
                    <x-ui.stat
                        :label="__('bancos::recaudo.web.dia.pendientes')"
                        :value="number_format($pending, 0, ',', '.')"
                        :hint="__('bancos::recaudo.web.dia.pendientes_ayuda')"
                        :tone="$pending > 0 ? 'warning' : 'success'"
                        :href="route('bancos.movimientos.index')"
                    />
                </div>

                @if (count($day->companies) > 1)
                    <div class="mt-4 grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4">
                        @foreach ($day->companies as $company)
                            <x-ui.stat :label="$company->label()">
                                <x-bancos::amount :value="$day->companyTotals[$company->value]" />
                            </x-ui.stat>
                        @endforeach
                    </div>
                @endif

                <div class="mt-6">
                    <h3 class="text-base font-semibold">{{ __('bancos::recaudo.web.dia.por_banco') }}</h3>
                    <p class="mb-3 text-sm text-fg-soft">{{ __('bancos::recaudo.web.dia.por_banco_descripcion') }}</p>

                    <x-ui.table :caption="__('bancos::recaudo.web.dia.tabla')" :caption-hidden="true" :columns="$bankColumns">
                        @foreach (Bank::cases() as $bank)
                            <tr>
                                <td>{{ $bank->label() }}</td>
                                @if (count($day->companies) > 1)
                                    @foreach ($day->companies as $company)
                                        <td data-align="end">
                                            @if ($day->amount($bank, $company) > 0)
                                                <x-bancos::amount :value="$day->amount($bank, $company)" />
                                            @else
                                                <span class="text-fg-muted">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                @endif
                                <td data-align="end">
                                    @if ($day->bankTotal($bank) > 0)
                                        <x-bancos::amount :value="$day->bankTotal($bank)" />
                                    @else
                                        <span class="text-fg-muted">{{ __('bancos::recaudo.web.dia.sin_cifras') }}</span>
                                    @endif
                                </td>
                                <td data-align="end">{{ $day->bankTotal($bank) > 0 ? number_format($day->bankShare($bank), 1, ',', '.').' %' : '—' }}</td>
                            </tr>
                        @endforeach
                        <x-slot:footer>
                            <tr>
                                <th scope="row">{{ __('bancos::recaudo.web.total_fila') }}</th>
                                @if (count($day->companies) > 1)
                                    @foreach ($day->companies as $company)
                                        <td data-align="end"><x-bancos::amount :value="$day->companyTotals[$company->value]" /></td>
                                    @endforeach
                                @endif
                                <td data-align="end"><x-bancos::amount :value="$day->total" /></td>
                                <td data-align="end">100 %</td>
                            </tr>
                        </x-slot:footer>
                    </x-ui.table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card
            :title="__('bancos::recaudo.web.mes.titulo', ['mes' => $month->month->locale('es')->isoFormat('MMMM [de] YYYY')])"
            :description="__('bancos::recaudo.web.mes.descripcion')"
        >
            @if ($previousMonth !== null || $nextMonth !== null)
                <nav aria-label="{{ __('bancos::recaudo.web.mes.navegacion') }}" class="mb-4 flex flex-wrap gap-3">
                    @if ($previousMonth !== null)
                        <x-ui.link-button :href="route('bancos.recaudo', ['fecha' => $previousMonth->format('Y-m-d')])" variant="secondary" icon="chevron-left">{{ __('bancos::recaudo.web.mes.anterior') }}</x-ui.link-button>
                    @endif

                    @if ($nextMonth !== null)
                        <x-ui.link-button :href="route('bancos.recaudo', ['fecha' => $nextMonth->format('Y-m-d')])" variant="secondary" icon="chevron-right">{{ __('bancos::recaudo.web.mes.siguiente') }}</x-ui.link-button>
                    @endif
                </nav>
            @endif

            <div class="grid gap-6">
                @foreach ($month->grids as $grid)
                    <x-ui.table :caption="__('bancos::recaudo.web.mes.tabla', ['empresa' => $grid->company->label(), 'codigo' => $grid->company->value])" :columns="$monthColumns">
                        @foreach ($grid->weeks as $week)
                            <tr>
                                <th scope="row" class="whitespace-nowrap">{{ $week->label() }}</th>
                                <td data-align="center">{{ $week->businessDays }}</td>

                                @foreach ($week->cells as $cell)
                                    @php($selected = $cell->state !== MonthCellState::Outside && $cell->date->toDateString() === $date->toDateString())

                                    <td
                                        data-align="end"
                                        @if ($selected) aria-current="date" @endif
                                        @class([
                                            'whitespace-nowrap',
                                            'bg-brand-tint text-brand-ink' => $selected,
                                            'bg-subtle' => ! $selected && $cell->state === MonthCellState::Outside,
                                            'bg-subtle/50' => ! $selected && $cell->state === MonthCellState::Holiday,
                                        ])
                                    >
                                        @switch($cell->state)
                                            @case(MonthCellState::Outside)
                                                <span data-visually-hidden>{{ __('bancos::recaudo.web.mes.fuera') }}</span>
                                                @break
                                            @case(MonthCellState::Holiday)
                                                <span class="text-sm text-fg-muted">{{ __('bancos::recaudo.web.mes.festivo') }}</span>
                                                @break
                                            @case(MonthCellState::Pending)
                                                <span aria-hidden="true" class="text-fg-muted">—</span>
                                                <span data-visually-hidden>{{ __('bancos::recaudo.web.mes.aun_no') }}</span>
                                                @break
                                            @default
                                                @if ($cell->amount > 0)
                                                    <a href="{{ route('bancos.recaudo', ['fecha' => $cell->date->format('Y-m-d')]) }}" class="inline-flex min-h-touch items-center font-semibold text-brand-ink underline-offset-4 hover:underline">
                                                        <x-bancos::amount :value="$cell->amount" />
                                                        <span data-visually-hidden>, {{ __('bancos::recaudo.web.mes.ver_dia', ['fecha' => $long($cell->date)]) }}</span>
                                                    </a>
                                                @else
                                                    <span aria-hidden="true" class="text-fg-muted">—</span>
                                                    <span data-visually-hidden>{{ __('bancos::recaudo.web.mes.sin_creditos') }}</span>
                                                @endif
                                        @endswitch
                                    </td>
                                @endforeach

                                <td data-align="end" class="font-semibold whitespace-nowrap">
                                    @if ($week->hasFigures())
                                        <x-bancos::amount :value="$week->total" />
                                    @else
                                        <span aria-hidden="true" class="font-normal text-fg-muted">—</span>
                                        <span data-visually-hidden>{{ __('bancos::recaudo.web.mes.aun_no') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        <x-slot:footer>
                            <tr>
                                <th scope="row">{{ __('bancos::recaudo.web.total_fila') }}</th>
                                <td data-align="center" class="font-semibold">{{ $grid->businessDays }}</td>
                                @foreach ($grid->columnTotals as $columnTotal)
                                    <td data-align="end" class="font-semibold whitespace-nowrap"><x-bancos::amount :value="$columnTotal" /></td>
                                @endforeach
                                <td data-align="end" class="font-bold whitespace-nowrap"><x-bancos::amount :value="$grid->total" /></td>
                            </tr>
                        </x-slot:footer>
                    </x-ui.table>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
