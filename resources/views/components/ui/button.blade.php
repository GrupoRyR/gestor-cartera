{{--
    <button> (C.5). The slot text is mandatory: with icon-only it is visually
    hidden but still names the button for screen readers. Other attributes
    (disabled, form, name, value, data-modal-open...) pass through.
--}}
@props(['variant' => 'primary', 'type' => 'button', 'icon' => null, 'iconOnly' => false])

@php
    if (! in_array($variant, ['primary', 'secondary', 'ghost', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown button variant [{$variant}]; use primary, secondary, ghost or danger.");
    }

    if (! in_array($type, ['button', 'submit', 'reset'], true)) {
        throw new InvalidArgumentException("Unknown button type [{$type}]; use button, submit or reset.");
    }

    if ($iconOnly && blank($icon)) {
        throw new InvalidArgumentException('An icon-only button needs an icon.');
    }

    if (! $slot->hasActualContent()) {
        throw new InvalidArgumentException('A button needs text, even when only its icon is visible.');
    }
@endphp

<button {{ $attributes->merge([
    'type' => $type,
    'data-variant' => $variant,
    'data-icon-only' => $iconOnly ? 'true' : null,
]) }}>
    @if (filled($icon))
        <x-ui.icon :name="$icon" />
    @endif
    <span @if ($iconOnly) data-visually-hidden @endif>{{ $slot }}</span>
</button>
