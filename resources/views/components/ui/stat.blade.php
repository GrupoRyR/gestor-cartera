{{--
    KPI tile (C.5). The value is already formatted; a non-empty default slot
    replaces it (e.g. <x-ui.money>). The trend is an icon plus text for
    screen readers; with href the whole tile is a link.

    Tone and trend are independent on purpose: a falling balance can be good
    news. The tone colors the dot before the label and the trend badge; the
    value stays in the text color (skill dataviz: text never wears status
    colors), in proportional figures because it stands alone.
--}}
@props([
    'label',
    'value' => null,
    'hint' => null,
    'trend' => null,
    'trendLabel' => null,
    'tone' => 'neutral',
    'href' => null,
])

@php
    if ($trend !== null && ! in_array($trend, ['up', 'down', 'flat'], true)) {
        throw new InvalidArgumentException("Unknown stat trend [{$trend}]; use up, down, flat or null.");
    }

    if (! in_array($tone, ['neutral', 'success', 'warning', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown stat tone [{$tone}]; use neutral, success, warning or danger.");
    }

    $trendLabel ??= match ($trend) {
        'up' => 'Sube',
        'down' => 'Baja',
        'flat' => 'Sin cambios',
        default => null,
    };

    $tag = filled($href) ? 'a' : 'div';

    // Fit the figure to the tile: the longer the text, the smaller the share
    // of the tile width per character (container units). "$ 1.250.000.000"
    // fits a quarter-width tile on desktop and a half-width tile on a phone.
    $shown = $slot->hasActualContent() ? trim(strip_tags((string) $slot)) : (filled($value) ? (string) $value : '—');
    $fit = round(100 / (0.56 * max(mb_strlen($shown), 4)), 1);

    $dot = match ($tone) {
        'success' => 'before:bg-status-active-dot',
        'warning' => 'before:bg-status-maintenance',
        'danger' => 'before:bg-status-failure',
        default => null,
    };

    $badge = match ($tone) {
        'success' => 'bg-status-active-bg text-status-active',
        'warning' => 'bg-status-maintenance-bg text-status-maintenance',
        'danger' => 'bg-status-failure-bg text-status-failure',
        default => 'bg-subtle text-fg-soft',
    };
@endphp

<{{ $tag }} {{ $attributes->merge([
    'href' => filled($href) ? $href : null,
    'data-tone' => $tone,
    'data-trend' => $trend,
])->class([
    '@container grid min-w-0 grid-cols-[minmax(0,1fr)_auto] content-start items-center gap-x-3 gap-y-1.5 rounded-card border border-line bg-surface p-4 text-fg no-underline md:p-5',
    'transition-[border-color,box-shadow] hover:border-line-strong hover:shadow-card' => filled($href),
]) }}>
    <span data-part="label" @class([
        'col-start-1 row-start-1 flex min-h-7 min-w-0 items-center gap-2 text-[0.8125rem] leading-snug font-semibold text-fg-soft hyphens-auto [overflow-wrap:anywhere] @max-[11rem]:col-span-2',
        "before:size-2 before:shrink-0 before:rounded-full {$dot}" => $dot !== null,
        'col-span-2' => $trend === null,
    ])>{{ $label }}</span>

    {{-- With very large text the figure may still need to wrap. --}}
    <span data-part="value" style="--stat-fit: {{ $fit }}cqi" class="col-span-2 row-start-2 min-w-0 text-[clamp(1rem,var(--stat-fit),2rem)] leading-[1.1] font-semibold tracking-[-0.015em] [overflow-wrap:anywhere] *:whitespace-normal">
        @if ($slot->hasActualContent())
            {{ $slot }}
        @else
            {{ filled($value) ? $value : '—' }}
        @endif
    </span>

    @if ($trend !== null)
        <span data-part="trend" class="col-start-2 row-start-1 inline-flex size-7 items-center justify-center rounded-full @max-[11rem]:col-start-1 @max-[11rem]:row-start-3 {{ $badge }}">
            <x-ui.icon :name="'trend-'.$trend" class="size-4" />
            <span data-visually-hidden>{{ $trendLabel }}</span>
        </span>
    @endif

    @if (filled($hint))
        <span data-part="hint" class="col-span-2 row-start-3 min-w-0 text-[0.8125rem] leading-snug text-fg-muted hyphens-auto [overflow-wrap:anywhere] @max-[11rem]:row-start-4">{{ $hint }}</span>
    @endif
</{{ $tag }}>
