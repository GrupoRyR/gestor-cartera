{{--
    Short label with an optional colored dot (C.5). The text is mandatory:
    color alone never carries meaning.
--}}
@props(['tone' => 'neutral', 'dot' => true])

@php
    if (! in_array($tone, ['neutral', 'success', 'warning', 'danger', 'info'], true)) {
        throw new InvalidArgumentException("Unknown chip tone [{$tone}]; use neutral, success, warning, danger or info.");
    }

    if (! $slot->hasActualContent()) {
        throw new InvalidArgumentException('A chip needs text: color alone never carries meaning.');
    }
@endphp

<span {{ $attributes->merge(['data-tone' => $tone]) }}>
    @if ($dot)
        <span data-part="dot" aria-hidden="true"></span>
    @endif
    <span data-part="text">{{ $slot }}</span>
</span>
