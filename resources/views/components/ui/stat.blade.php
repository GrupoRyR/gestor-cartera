{{--
    KPI tile (C.5). The value is already formatted; a non-empty default slot
    replaces it (e.g. <x-ui.money>). The trend is an icon plus text for
    screen readers; with href the whole tile is a link.
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
@endphp

<{{ $tag }} {{ $attributes->merge([
    'href' => filled($href) ? $href : null,
    'data-tone' => $tone,
    'data-trend' => $trend,
]) }}>
    <span data-part="label">{{ $label }}</span>

    <span data-part="value">
        @if ($slot->hasActualContent())
            {{ $slot }}
        @else
            {{ filled($value) ? $value : '—' }}
        @endif
    </span>

    @if ($trend !== null)
        <span data-part="trend">
            <x-ui.icon :name="'trend-'.$trend" />
            <span data-visually-hidden>{{ $trendLabel }}</span>
        </span>
    @endif

    @if (filled($hint))
        <span data-part="hint">{{ $hint }}</span>
    @endif
</{{ $tag }}>
