@use('App\View\Components\Support\ButtonStyle')

{{--
    Link that looks like a button (C.5). Same variants as <x-ui.button>. An
    external link opens in a new tab, shows an arrow and says so to screen
    readers.
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
    'data-brand-surface' => ButtonStyle::isBrandSurface($variant) ? true : null,
    'target' => $external ? '_blank' : null,
    'rel' => $external ? 'noopener noreferrer' : null,
])->class(ButtonStyle::classes($variant, (bool) $iconOnly)) }}>
    @if (filled($icon))
        <x-ui.icon :name="$icon" />
    @endif
    <span @if ($iconOnly) data-visually-hidden @endif>{{ $slot }}</span>
    @if ($external)
        @unless ($iconOnly)
            <x-ui.icon name="external" class="size-4 opacity-70" />
        @endunless
        <span data-visually-hidden> (se abre en otra pestaña)</span>
    @endif
</a>
