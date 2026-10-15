@use('App\Modules\Core\Contracts\Enums\ClientStatus')

{{--
    Collection status of a client (C.5). A string must be a ClientStatus
    value: ClientStatus::from() fails loudly on anything else.

    Routine states use a soft fill; the three that ask for attention or close
    a case (incumplido, pago recibido, jurídico) use a solid fill, so they
    stand out in long lists and on the Kanban. Every badge keeps its text and
    a dot: color never carries the meaning alone.
--}}
@props(['state'])

@php
    $status = $state instanceof ClientStatus ? $state : ClientStatus::from($state);

    $colors = match ($status) {
        ClientStatus::Current => 'bg-status-active-bg text-status-active',
        ClientStatus::DueSoon => 'bg-status-maintenance-bg text-status-maintenance',
        ClientStatus::Overdue => 'bg-status-failure-bg text-status-failure',
        ClientStatus::InProgress => 'bg-status-local-bg text-status-local',
        ClientStatus::Committed => 'bg-status-scheduled-bg text-status-scheduled',
        // Solid fills: white text in light mode, dark text in dark mode (--color-surface flips).
        ClientStatus::Defaulted => 'bg-status-failure text-surface',
        ClientStatus::PaymentReceived => 'bg-status-active text-surface',
        ClientStatus::Legal => 'bg-fg text-surface',
    };
@endphp

<span {{ $attributes->merge(['data-state' => $status->value])->class("inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 align-middle text-[0.8125rem] leading-tight font-semibold whitespace-nowrap before:size-1.5 before:shrink-0 before:rounded-full before:bg-current {$colors}") }}>{{ $status->label() }}</span>@php /* Ending on a PHP tag drops the final newline: no stray space before "." or "," after the component. */ @endphp
