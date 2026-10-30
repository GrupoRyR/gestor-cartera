@use('App\View\Components\Support\ToneStyle')

{{--
    Short label with an optional colored dot (C.5). The text is mandatory:
    color alone never carries meaning. The neutral tone is the brand label
    (tint fill, ink text); the other tones are states.
--}}
@props(['tone' => 'neutral', 'dot' => true])

@php
    if (! in_array($tone, ['neutral', 'success', 'warning', 'danger', 'info'], true)) {
        throw new InvalidArgumentException("Unknown chip tone [{$tone}]; use neutral, success, warning, danger or info.");
    }

    if (! $slot->hasActualContent()) {
        throw new InvalidArgumentException('A chip needs text: color alone never carries meaning.');
    }

    $colors = ToneStyle::soft($tone);
@endphp

<span {{ $attributes->merge(['data-tone' => $tone])->class("inline-flex max-w-full items-center gap-1.5 rounded-lg px-2.5 py-1 align-middle text-[0.8125rem] leading-tight font-semibold {$colors}") }}>
    @if ($dot)
        <span data-part="dot" aria-hidden="true" @class(['size-1.5 shrink-0 rounded-full', 'bg-status-active-dot' => $tone === 'success', 'bg-current' => $tone !== 'success'])></span>
    @endif
    <span data-part="text">{{ $slot }}</span>
</span>@php /* Ending on a PHP tag drops the final newline: no stray space before "." or "," after the component. */ @endphp
