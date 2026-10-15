@use('App\View\Components\Support\ToneStyle')

{{--
    Message box (C.5). Warnings and errors use role="alert"; information and
    success use role="status". A dismissible alert closes with Alpine. The
    icon and the title take the tone color; the message stays in the text
    color for contrast.
--}}
@props(['tone' => 'info', 'title' => null, 'dismissible' => false])

@php
    if (! in_array($tone, ['info', 'success', 'warning', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown alert tone [{$tone}]; use info, success, warning or danger.");
    }

    $colors = ToneStyle::panel($tone);
    $icon = $tone;
@endphp

<div {{ $attributes->merge([
    'role' => in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status',
    'data-tone' => $tone,
    'x-data' => $dismissible ? '{ open: true }' : null,
    'x-show' => $dismissible ? 'open' : null,
])->class("flex items-start gap-3 rounded-control px-4 py-3 text-[0.9375rem] text-fg {$colors}") }}>
    <x-ui.icon :name="$icon" class="mt-0.5 size-5 text-[color:var(--tone-accent)]" />

    <div data-part="content" class="min-w-0 flex-1">
        @if (filled($title))
            <p data-part="title" class="font-bold text-[color:var(--tone-accent)]">{{ $title }}</p>
        @endif
        <div data-part="body" @class(['leading-snug', 'mt-0.5' => filled($title)])>{{ $slot }}</div>
    </div>

    @if ($dismissible)
        <x-ui.button variant="ghost" icon="close" :icon-only="true" x-on:click="open = false" class="-my-2 -me-2">Cerrar aviso</x-ui.button>
    @endif
</div>
