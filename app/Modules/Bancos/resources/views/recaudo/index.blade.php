@use('App\Modules\Core\Contracts\Enums\Bank')
@use('App\Modules\Core\Contracts\Enums\CompanyCode')

{{--
    GET /bancos/recaudo (bancos.recaudo): the daily collections report and the
    weekly accumulated of the same week.
    $date: the day of the report; $today: today (Bogotá); $day: DayReport;
    $week: WeekReport; $pending: credits of the day still to apply in SAP;
    $otherCurrency: credits of the day in a currency that does not add up.

    The bars follow the dataviz rules for magnitude: one hue (the brand), every
    bar labelled with its amount, so nothing depends on color or hover, and
    the same numbers one click away as a table.
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

    $weekColumns = [
        ['key' => 'day', 'label' => __('bancos::recaudo.web.semana.columnas.dia')],
        ['key' => 'total', 'label' => __('bancos::recaudo.web.semana.columnas.recaudo'), 'align' => 'end'],
        ['key' => 'cumulative', 'label' => __('bancos::recaudo.web.semana.columnas.acumulado'), 'align' => 'end'],
    ];

    $change = $week->change();
    $trendLabel = match ($week->trend()) {
        'up' => __('bancos::recaudo.web.semana.sube'),
        'down' => __('bancos::recaudo.web.semana.baja'),
        'flat' => __('bancos::recaudo.web.semana.igual'),
        default => null,
    };
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
            :title="__('bancos::recaudo.web.semana.titulo', ['desde' => $short($week->start), 'hasta' => $short($week->end)])"
            :description="__('bancos::recaudo.web.semana.descripcion')"
        >
            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-3">
                <x-ui.stat
                    :label="__('bancos::recaudo.web.semana.total')"
                    :hint="__('bancos::recaudo.web.semana.dias', ['n' => $week->daysCounted])"
                    :trend="$week->trend()"
                    :trend-label="$trendLabel"
                >
                    <x-bancos::amount :value="$week->total" />
                </x-ui.stat>

                @if ($week->daysCounted > 0)
                    <x-ui.stat
                        :label="__('bancos::recaudo.web.semana.anterior')"
                        :hint="$change !== null ? __('bancos::recaudo.web.semana.cambio', ['cambio' => ($change > 0 ? '+' : '').number_format($change, 1, ',', '.')]) : null"
                    >
                        <x-bancos::amount :value="$week->previousTotal" />
                    </x-ui.stat>
                @endif

                @if (count($week->companyTotals) > 1)
                    @foreach ($week->companyTotals as $code => $total)
                        <x-ui.stat :label="CompanyCode::from($code)->label()">
                            <x-bancos::amount :value="$total" />
                        </x-ui.stat>
                    @endforeach
                @endif
            </div>

            <div data-part="week-bars" class="mt-6 grid gap-4">
                <h3 class="text-base font-semibold">{{ __('bancos::recaudo.web.semana.por_dia') }}</h3>

                <ul class="grid gap-4">
                    @foreach ($week->days as $weekDay)
                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 sm:grid-cols-[10rem_minmax(0,1fr)_minmax(10rem,auto)]">
                            <span class="text-sm font-semibold text-fg-soft sm:col-start-1 sm:row-start-1">{{ $weekDay->date->locale('es')->isoFormat('dddd D') }}</span>
                            <span class="text-end text-sm tabular-nums sm:col-start-3 sm:row-start-1">
                                @if (! $weekDay->loaded)
                                    <span class="text-fg-muted">{{ __('bancos::recaudo.web.semana.aun_no') }}</span>
                                @elseif ($weekDay->total <= 0)
                                    <span class="text-fg-muted">{{ __('bancos::recaudo.web.semana.sin_creditos') }}</span>
                                @else
                                    <x-bancos::amount :value="$weekDay->total" class="font-semibold" />
                                @endif
                            </span>
                            <span aria-hidden="true" class="col-span-2 h-3 overflow-hidden rounded-full bg-subtle sm:col-span-1 sm:col-start-2 sm:row-start-1">
                                <span class="block h-full rounded-full bg-brand-primary" style="width: {{ $week->barWidth($weekDay) }}%"></span>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <details class="text-sm">
                    <summary class="inline-flex min-h-touch cursor-pointer items-center font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('bancos::recaudo.web.semana.tabla') }}</summary>
                    <x-ui.table :caption="__('bancos::recaudo.web.semana.tabla_titulo')" :caption-hidden="true" :columns="$weekColumns">
                        @foreach ($week->days as $weekDay)
                            <tr>
                                <td>{{ $weekDay->date->locale('es')->isoFormat('dddd D') }}</td>
                                <td data-align="end">
                                    @if ($weekDay->loaded)
                                        <x-bancos::amount :value="$weekDay->total" />
                                    @else
                                        <span class="text-fg-muted">{{ __('bancos::recaudo.web.semana.aun_no') }}</span>
                                    @endif
                                </td>
                                <td data-align="end">
                                    @if ($weekDay->cumulative !== null)
                                        <x-bancos::amount :value="$weekDay->cumulative" />
                                    @else
                                        <span class="text-fg-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </details>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
