@use('App\Modules\Asesores\Domain\Chart\LineChart')
@use('App\Modules\Asesores\Domain\Format\Percent')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    Overdue balance at each cutoff, as one line (skill dataviz: change over
    time, a single series). The horizontal axis is the calendar and the
    vertical one starts at zero. The drawing is SVG built on the server
    (Domain\Chart\LineChart); Alpine adds a hover readout. The dates, the
    highest value and a summary sit in real text under it, and the same
    numbers are one click away as a table, so keyboard and screen reader
    users lose nothing. $history is PortfolioTotals, oldest first.
--}}
@props(['history', 'id' => 'tendencia', 'table' => true])

@php
    $series = [];

    foreach ($history as $totals) {
        if ($totals->cutoffDate !== null) {
            $series[] = ['date' => $totals->cutoffDate, 'value' => $totals->overdue];
        }
    }

    $chart = count($series) >= 2 ? LineChart::build($series) : null;
    $first = $series[0] ?? null;
    $last = $series === [] ? null : $series[array_key_last($series)];
    $change = $first !== null && $last !== null ? $last['value'] - $first['value'] : 0.0;

    $tips = [];

    foreach ($chart?->points ?? [] as $point) {
        $tips[] = [
            'x' => round($point->x, 1),
            'y' => round($point->y, 1),
            'label' => DateFormat::date($point->date).' · '.Money::format($point->value),
        ];
    }

    $summary = $chart === null ? '' : trim(
        __('asesores::historico.grafico.resumen', ['cortes' => count($series), 'desde' => DateFormat::date($first['date']), 'hasta' => DateFormat::date($last['date'])]).' '.match (true) {
            round($change) > 0 => __('asesores::historico.grafico.subio', ['valor' => Money::format($change)]),
            round($change) < 0 => __('asesores::historico.grafico.bajo', ['valor' => Money::format(abs($change))]),
            default => __('asesores::historico.grafico.igual'),
        },
    );

    $columns = [
        ['key' => 'fecha', 'label' => __('asesores::historico.tabla.columnas.fecha')],
        ['key' => 'saldo', 'label' => __('asesores::historico.tabla.columnas.saldo'), 'align' => 'end'],
        ['key' => 'vencido', 'label' => __('asesores::historico.tabla.columnas.vencido'), 'align' => 'end'],
        ['key' => 'porcentaje', 'label' => __('asesores::historico.tabla.columnas.porcentaje'), 'align' => 'end'],
        ['key' => 'mas_90', 'label' => __('asesores::historico.tabla.columnas.mas_90'), 'align' => 'end'],
    ];
@endphp

@if ($chart === null)
    <x-ui.empty icon="history" :title="__('asesores::historico.grafico.poca_historia')" />
@else
    <div data-part="trend-chart" class="grid gap-3">
        <div
            x-data="{
                points: @js($tips),
                width: {{ $chart->width }},
                i: null,
                move(event) {
                    const box = event.currentTarget.getBoundingClientRect();
                    const x = ((event.clientX - box.left) / box.width) * this.width;
                    let best = 0;
                    for (let n = 1; n < this.points.length; n++) {
                        if (Math.abs(this.points[n].x - x) < Math.abs(this.points[best].x - x)) best = n;
                    }
                    this.i = best;
                },
            }"
            class="relative"
        >
            <svg
                viewBox="0 0 {{ $chart->width }} {{ $chart->height }}"
                role="img"
                aria-labelledby="{{ $id }}-titulo"
                class="block h-auto w-full touch-pan-y"
                x-on:pointermove="move($event)"
                x-on:pointerleave="i = null"
            >
                <title id="{{ $id }}-titulo">{{ $summary }}</title>
                <line x1="0" x2="{{ $chart->width }}" y1="{{ $chart->topLine }}" y2="{{ $chart->topLine }}" stroke-width="1" class="stroke-line" />
                <line x1="0" x2="{{ $chart->width }}" y1="{{ $chart->baseline }}" y2="{{ $chart->baseline }}" stroke-width="1" class="stroke-line-strong" />
                <path d="{{ $chart->area() }}" class="fill-brand-primary/10" />
                <path d="{{ $chart->path() }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" class="stroke-brand-primary" />
                @php($end = $chart->points[array_key_last($chart->points)])
                <circle cx="{{ $end->x }}" cy="{{ $end->y }}" r="6" stroke-width="2" class="fill-brand-primary stroke-surface" />

                <g x-cloak x-show="i !== null" aria-hidden="true">
                    <line x-bind:x1="points[i]?.x ?? 0" x-bind:x2="points[i]?.x ?? 0" y1="{{ $chart->topLine }}" y2="{{ $chart->baseline }}" stroke-width="1" class="stroke-fg-muted" />
                    <circle x-bind:cx="points[i]?.x ?? 0" x-bind:cy="points[i]?.y ?? 0" r="6" stroke-width="2" class="fill-brand-primary stroke-surface" />
                </g>
            </svg>

            <p
                x-cloak
                x-show="i !== null"
                x-text="points[i]?.label"
                aria-hidden="true"
                class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-[130%] rounded-control border border-line bg-surface px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-fg shadow-pop"
                x-bind:style="i === null ? '' : `left: ${points[i].x / width * 100}%; top: ${points[i].y / {{ $chart->height }} * 100}%`"
            ></p>
        </div>

        <div class="flex flex-wrap items-baseline justify-between gap-x-4 text-xs text-fg-muted">
            <span>{{ DateFormat::date($first['date']) }}</span>
            <span>{{ __('asesores::resumen.kpis.vencido') }}: {{ Money::format($last['value']) }}</span>
            <span>{{ DateFormat::date($last['date']) }}</span>
        </div>

        <p class="text-sm text-fg-soft">{{ $summary }}</p>

        @if ($table)
            <details class="text-sm">
                <summary class="inline-flex min-h-touch cursor-pointer items-center font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('asesores::historico.grafico.tabla') }}</summary>
                <x-ui.table :caption="__('asesores::historico.tabla.titulo')" :caption-hidden="true" :columns="$columns">
                    @foreach (array_reverse($history) as $totals)
                        @continue($totals->cutoffDate === null)
                        <tr>
                            <td><x-ui.date :value="$totals->cutoffDate" /></td>
                            <td data-align="end"><x-ui.money :value="$totals->total" /></td>
                            <td data-align="end"><x-ui.money :value="$totals->overdue" /></td>
                            <td data-align="end">{{ Percent::format($totals->overduePercent()) }}</td>
                            <td data-align="end"><x-ui.money :value="$totals->aging->over90" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </details>
        @endif
    </div>
@endif
