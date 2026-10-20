@use('App\Modules\Gerencia\Http\Presenters\LineChartGeometry')
@use('Illuminate\Support\Str')

{{--
    Line chart with one line per series (<x-gerencia::line-chart>), drawn as
    SVG on the server (skill dataviz: change over time, a single measure,
    one axis). Every series carries the same points in the same order; a
    point without a value breaks the line.

    $series: list of ['name', 'tone' => group|sgx|sdc|neutral, 'points' =>
    list of ['label', 'value' => ?float, 'text', 'title' =>? string]]. The
    color follows the entity (tone), never its position; the group is
    dashed so it differs from the companies without relying on color.
    $label names the chart for screen readers. $format is percent or money
    (axis text). The drawing says its values in <title> elements (hover and
    screen readers); the legend is plain HTML under it, and the page offers
    the same numbers as a table.

    Tailwind only sees literal class names, so the tone table spells them out.
--}}
@props(['series', 'label', 'format' => 'percent'])

@php
    $tones = [
        'group' => ['stroke' => 'stroke-fg-muted', 'fill' => 'fill-fg-muted', 'swatch' => 'border-fg-muted', 'dashed' => true],
        'sgx' => ['stroke' => 'stroke-viz-1', 'fill' => 'fill-viz-1', 'swatch' => 'border-viz-1', 'dashed' => false],
        'sdc' => ['stroke' => 'stroke-viz-2', 'fill' => 'fill-viz-2', 'swatch' => 'border-viz-2', 'dashed' => false],
        'neutral' => ['stroke' => 'stroke-fg-soft', 'fill' => 'fill-fg-soft', 'swatch' => 'border-fg-soft', 'dashed' => false],
    ];

    foreach ($series as $line) {
        if (! isset($tones[$line['tone']])) {
            throw new InvalidArgumentException("Unknown line-chart tone [{$line['tone']}]; use group, sgx, sdc or neutral.");
        }
    }

    $chart = LineChartGeometry::build($series, $format);
    $id = 'chart-'.Str::lower(Str::random(8));
    $plot = $chart['plot'];

    // What a screen reader hears: the latest value of every line.
    $summary = implode('. ', array_filter(array_map(
        fn (array $line): ?string => $line['points'] === [] ? null : $line['points'][array_key_last($line['points'])]['title'],
        $chart['lines'],
    )));
@endphp

<figure {{ $attributes->class('grid gap-3') }}>
    <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-labelledby="{{ $id }}-title {{ $id }}-desc" class="block h-auto w-full max-w-3xl">
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
                <path d="{{ $path }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" @if ($tone['dashed']) stroke-dasharray="6 4" @endif class="{{ $tone['stroke'] }}" />
            @endforeach

            @foreach ($line['points'] as $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4.5" stroke-width="2" class="{{ $tone['fill'] }} stroke-surface" />
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="12" class="fill-transparent"><title>{{ $point['title'] }}</title></circle>
            @endforeach
        @endforeach
    </svg>

    @if (count($chart['lines']) > 1)
        <ul data-part="legend" class="flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-fg-soft">
            @foreach ($chart['lines'] as $line)
                @php($tone = $tones[$line['tone']])
                <li class="flex items-center gap-2">
                    <span aria-hidden="true" @class(['w-5 shrink-0 border-t-[3px]', $tone['swatch'], 'border-dashed' => $tone['dashed']])></span>
                    <span>{{ $line['name'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</figure>
