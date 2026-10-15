{{--
    Link that looks like a button (C.5). Same variants as <x-ui.button>. An
    external link opens in a new tab and says so to screen readers.
--}}
@props(['href', 'variant' => 'primary', 'icon' => null, 'iconOnly' => false, 'external' => false])

@php
    if (! in_array($variant, ['primary', 'secondary', 'ghost', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown link-button variant [{$variant}]; use primary, secondary, ghost or danger.");
    }

    if ($iconOnly && blank($icon)) {
        throw new InvalidArgumentException('An icon-only link-button needs an icon.');
    }

    if (! $slot->hasActualContent()) {
        throw new InvalidArgumentException('A link-button needs text, even when only its icon is visible.');
    }
@endphp

<a {{ $attributes->merge([
    'href' => $href,
    'data-variant' => $variant,
    'data-icon-only' => $iconOnly ? 'true' : null,
    'target' => $external ? '_blank' : null,
    'rel' => $external ? 'noopener noreferrer' : null,
]) }}>
    @if (filled($icon))
        <x-ui.icon :name="$icon" />
    @endif
    <span @if ($iconOnly) data-visually-hidden @endif>{{ $slot }}</span>
    @if ($external)
        <span data-visually-hidden> (se abre en otra pestaña)</span>
    @endif
</a>
