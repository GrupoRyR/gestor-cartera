{{--
    Message box (C.5). Warnings and errors use role="alert"; information and
    success use role="status". A dismissible alert closes with Alpine.
--}}
@props(['tone' => 'info', 'title' => null, 'dismissible' => false])

@php
    if (! in_array($tone, ['info', 'success', 'warning', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown alert tone [{$tone}]; use info, success, warning or danger.");
    }
@endphp

<div {{ $attributes->merge([
    'role' => in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status',
    'data-tone' => $tone,
    'x-data' => $dismissible ? '{ open: true }' : null,
    'x-show' => $dismissible ? 'open' : null,
]) }}>
    <div data-part="content">
        @if (filled($title))
            <p data-part="title">{{ $title }}</p>
        @endif
        <div data-part="body">{{ $slot }}</div>
    </div>

    @if ($dismissible)
        <x-ui.button variant="ghost" icon="close" :icon-only="true" x-on:click="open = false">Cerrar aviso</x-ui.button>
    @endif
</div>
