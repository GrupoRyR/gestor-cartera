@use('App\Modules\Gerencia\Http\Presenters\ChartScale')
@use('App\Modules\Gerencia\Http\Presenters\LineChartGeometry')
@use('Illuminate\Support\Str')

{{--
    Line chart with one line per series (<x-gerencia::line-chart>), drawn as
    SVG on the server (skill dataviz: change over time, a single measure,
    one axis). Every series carries the same points in the same order; a
    point without a value breaks the line.

    $series: list of ['name', 'tone' => brand|group|sgx|sdc, 'points' =>
    list of ['label', 'value' => ?float, 'text', 'title' =>? string]].
    One company per screen (brand v2): a lone series uses brand, the accent
    of the active company. Several series (the group view) never carry a
    brand hue, so orange and blue never meet: group is the solid dark line,
    sgx the dashed gray one and sdc the dotted gray one. The pattern follows
    the entity, never its position, and tells the lines apart without color.
    $label names the chart for screen readers. $format is percent or money
    (axis text). The drawing says its values in <title> elements (hover and
    screen readers). With several lines, each one ends in its name and the
    legend under the drawing repeats the patterns; the page offers the same
    numbers as a table.

    Tailwind only sees literal class names, so the tone table spells them out.
--}}
@props(['series', 'label', 'format' => 'percent'])

@php
    $tones = [
        'brand' => ['stroke' => 'stroke-brand-accent', 'fill' => 'fill-brand-accent', 'text' => 'fill-fg', 'swatch' => 'border-brand-accent border-solid', 'dash' => null],
        'group' => ['stroke' => 'stroke-fg', 'fill' => 'fill-fg', 'text' => 'fill-fg', 'swatch' => 'border-fg border-solid', 'dash' => null],
        'sgx' => ['stroke' => 'stroke-fg-muted', 'fill' => 'fill-fg-muted', 'text' => 'fill-fg-muted', 'swatch' => 'border-fg-muted border-dashed', 'dash' => '6 4'],
        // A dash of 1 with round caps draws a dot 3 units long and leaves a 3-unit gap.
        'sdc' => ['stroke' => 'stroke-fg-muted', 'fill' => 'fill-fg-muted', 'text' => 'fill-fg-muted', 'swatch' => 'border-fg-muted border-dotted', 'dash' => '1 5'],
    ];

    foreach ($series as $line) {
        if (! isset($tones[$line['tone']])) {
            throw new InvalidArgumentException("Unknown line-chart tone [{$line['tone']}]; use brand, group, sgx or sdc.");
        }
    }

    $chart = LineChartGeometry::build($series, $format);
    $id = 'chart-'.Str::lower(Str::random(8));
    $plot = $chart['plot'];
    $width = $chart['width'];

    // End labels (several lines only): the series name right of its last
    // point, nudged apart vertically so two labels never overlap, kept on
    // the plot, and the drawing widened so no label is cut off.
    $labelGap = 14.0;
    $endLabels = [];

    if (count($chart['lines']) > 1) {
        foreach ($chart['lines'] as $line) {
            if ($line['points'] !== []) {
                $last = $line['points'][array_key_last($line['points'])];
                $endLabels[] = ['x' => $last['x'] + 8, 'y' => $last['y'], 'text' => $line['name'], 'class' => $tones[$line['tone']]['text']];
            }
        }

        usort($endLabels, fn (array $a, array $b): int => $a['y'] <=> $b['y']);
        $count = count($endLabels);

        for ($i = 1; $i < $count; $i++) {
            $endLabels[$i]['y'] = max($endLabels[$i]['y'], $endLabels[$i - 1]['y'] + $labelGap);
        }

        for ($i = $count - 1; $i >= 0; $i--) {
            $ceiling = $i === $count - 1 ? $plot['bottom'] : $endLabels[$i + 1]['y'] - $labelGap;
            $endLabels[$i]['y'] = max($plot['top'], min($endLabels[$i]['y'], $ceiling));
        }

        foreach ($endLabels as $endLabel) {
            $width = max($width, (int) ceil($endLabel['x'] + ChartScale::labelWidth([$endLabel['text']]) + 4));
        }
    }

    // What a screen reader hears: the latest value of every line.
    $summary = implode('. ', array_filter(array_map(
        fn (array $line): ?string => $line['points'] === [] ? null : $line['points'][array_key_last($line['points'])]['title'],
        $chart['lines'],
    )));
@endphp

<figure data-chart="line" {{ $attributes->class('grid gap-3') }}>
    <svg viewBox="0 0 {{ $width }} {{ $chart['height'] }}" role="img" aria-labelledby="{{ $id }}-title {{ $id }}-desc" class="block h-auto w-full max-w-3xl">
        <title id="{{ $id }}-title">{{ $label }}</title>
        <desc id="{{ $id }}-desc">{{ $summary }}</desc>

        @foreach ($chart['ticks'] as $tick)
            <line x1="{{ $plot['left'] }}" x2="{{ $plot['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke-width="1" class="{{ $loop->first ? 'stroke-line-strong' : 'stroke-line' }}" />
            <text x="{{ $plot['left'] - 8 }}" y="{{ $tick['y'] }}" dy="0.32em" text-anchor="end" class="fill-fg-muted text-xs">{{ $tick['label'] }}</text>
        @endforeach

        @foreach ($chart['xLabels'] as $xLabel)
            <text x="{{ $xLabel['x'] }}" y="{{ $plot['bottom'] + 20 }}" text-anchor="middle" class="fill-fg-muted text-xs">{{ $xLabel['text'] }}</text>
        @endforeach

        @foreach ($chart['lines'] as $line)
            @php($tone = $tones[$line['tone']])

            @foreach ($line['paths'] as $path)
                <path d="{{ $path }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" @if ($tone['dash'] !== null) stroke-dasharray="{{ $tone['dash'] }}" @endif class="{{ $tone['stroke'] }}" />
            @endforeach

            @foreach ($line['points'] as $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4.5" stroke-width="2" class="{{ $tone['fill'] }} stroke-surface" />
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="12" class="fill-transparent"><title>{{ $point['title'] }}</title></circle>
            @endforeach
        @endforeach

        @foreach ($endLabels as $endLabel)
            <text data-part="end-label" x="{{ ChartScale::coordinate($endLabel['x']) }}" y="{{ ChartScale::coordinate($endLabel['y']) }}" dy="0.32em" class="{{ $endLabel['class'] }} text-xs">{{ $endLabel['text'] }}</text>
        @endforeach
    </svg>

    @if (count($chart['lines']) > 1)
        <ul data-part="legend" class="flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-fg-muted">
            @foreach ($chart['lines'] as $line)
                @php($tone = $tones[$line['tone']])
                <li class="flex items-center gap-2">
                    <span aria-hidden="true" class="w-5 shrink-0 border-t-[3px] {{ $tone['swatch'] }}"></span>
                    <span>{{ $line['name'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</figure>
