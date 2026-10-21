@use('App\Modules\Comisiones\Infrastructure\Report\Labels')

{{--
    Compliance against budget per code (skill dataviz: magnitude as
    horizontal bars in one hue, viz-1; no value ramp, since the codes have no
    order). Each track carries a thin mark at the code's minimum, and the
    label says the compliance and whether it meets the minimum in words, so
    nothing depends on color or on the mark. The table of the dashboard holds
    the same figures and is the source of truth.

    $chart: ['scale' => float, 'bars' => list<ComplianceBar>] from
    ComplianceBars::of(); $tableId: id of that table, for the link.
--}}
@props(['chart', 'tableId'])

@if ($chart['bars'] === [])
    <x-ui.empty icon="chart" :title="__('comisiones::tablero.avance.vacio')" />
@else
    <div data-part="compliance-bars" class="grid gap-4">
        <ul class="@container grid gap-4">
            @foreach ($chart['bars'] as $bar)
                @php
                    $code = $bar->code;
                    $minimum = Labels::rate($code->rule->minimumCompliance);
                    $verdict = match (true) {
                        $bar->minimumAt === null => __('comisiones::tablero.avance.sin_minimo'),
                        $code->meetsMinimum => __('comisiones::tablero.avance.cumple', ['minimo' => $minimum]),
                        default => __('comisiones::tablero.avance.no_cumple', ['minimo' => $minimum]),
                    };
                @endphp
                <li data-bar="{{ $code->code() }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 @xl:grid-cols-[12rem_minmax(0,1fr)_minmax(13rem,auto)]">
                    <span class="min-w-0 truncate text-sm font-semibold text-fg-soft @xl:col-start-1 @xl:row-start-1">{{ $code->code() }} · {{ $code->rule->name }}</span>
                    <span class="text-end text-sm @xl:col-start-3 @xl:row-start-1">
                        <span class="font-semibold tabular-nums">{{ Labels::percent($code->compliance) }}</span>
                        <span class="text-fg-muted">· {{ $verdict }}</span>
                    </span>
                    <span aria-hidden="true" class="relative col-span-2 h-3 rounded-full bg-subtle @xl:col-span-1 @xl:col-start-2 @xl:row-start-1">
                        <span class="block h-full rounded-full bg-viz-1" style="width: {{ $bar->width }}%"></span>
                        @if ($bar->minimumAt !== null)
                            <span class="absolute -top-1 -bottom-1 w-0.5 -translate-x-1/2 rounded-full bg-fg" style="left: {{ $bar->minimumAt }}%"></span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>

        <div class="flex flex-wrap items-center justify-between gap-2 text-[0.8125rem] text-fg-muted">
            <span>{{ __('comisiones::tablero.avance.eje', ['maximo' => (int) $chart['scale']]) }}</span>
            <a href="#{{ $tableId }}" class="inline-flex min-h-touch items-center font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('comisiones::tablero.avance.ver_tabla') }}</a>
        </div>
    </div>
@endif
