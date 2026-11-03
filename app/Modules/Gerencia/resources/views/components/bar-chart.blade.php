@use('App\Modules\Gerencia\Http\Presenters\BarChartGeometry')
@use('Illuminate\Support\Str')

{{--
    Bar chart of one measure (<x-gerencia::bar-chart>), drawn as SVG on the
    server (skill dataviz: amounts by period, a single series, a baseline at
    zero). Each bar has a 2-unit gap to its neighbours and a rounded top; a
    <title> over every column says its value (hover and screen readers).

    $bars: list of ['label', 'value' => float, 'text', 'title' =>? string].
    $tone: brand (one company on screen: the active brand accent) or group
    (several companies: gray, so orange and blue never meet). $label names
    the chart for screen readers. $format is money or percent (axis text).
    The page offers the same numbers as a table.

    Tailwind only sees literal class names, so the tone table spells them out.
--}}
@props(['bars', 'label', 'tone' => 'group', 'format' => 'money'])

@php
    $fills = [
        'brand' => 'fill-brand-accent',
        'group' => 'fill-fg-muted',
    ];

    if (! isset($fills[$tone])) {
        throw new InvalidArgumentException("Unknown bar-chart tone [{$tone}]; use brand or group.");
    }

    $chart = BarChartGeometry::build($bars, $format);
    $id = 'chart-'.Str::lower(Str::random(8));
    $plot = $chart['plot'];
@endphp

<figure data-chart="bar" {{ $attributes->class('grid gap-3') }}>
    <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-labelledby="{{ $id }}-title" class="block h-auto w-full max-w-3xl">
        <title id="{{ $id }}-title">{{ $label }}</title>

        @foreach ($chart['ticks'] as $tick)
            <line x1="{{ $plot['left'] }}" x2="{{ $plot['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke-width="1" class="{{ $loop->first ? 'stroke-line-strong' : 'stroke-line' }}" />
            <text x="{{ $plot['left'] - 8 }}" y="{{ $tick['y'] }}" dy="0.32em" text-anchor="end" class="fill-fg-muted text-xs">{{ $tick['label'] }}</text>
        @endforeach

        @foreach ($chart['xLabels'] as $xLabel)
            <text x="{{ $xLabel['x'] }}" y="{{ $plot['bottom'] + 20 }}" text-anchor="middle" class="fill-fg-muted text-xs">{{ $xLabel['text'] }}</text>
        @endforeach

        @foreach ($chart['bars'] as $bar)
            @if ($bar['path'] !== null)
                <path d="{{ $bar['path'] }}" class="{{ $fills[$tone] }}" />
            @endif
            <rect x="{{ $bar['slotX'] }}" y="{{ $plot['top'] }}" width="{{ $bar['slotWidth'] }}" height="{{ $plot['bottom'] - $plot['top'] }}" class="fill-transparent"><title>{{ $bar['title'] }}</title></rect>
        @endforeach
    </svg>
</figure>
