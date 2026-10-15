@use('App\Modules\Core\Contracts\Enums\ClientStatus')

{{--
    Collection status of a client (C.5). A string must be a ClientStatus
    value: ClientStatus::from() fails loudly on anything else. Phase 0b
    colors each status from data-state.
--}}
@props(['state'])

@php
    $status = $state instanceof ClientStatus ? $state : ClientStatus::from($state);
@endphp

<span {{ $attributes->merge(['data-state' => $status->value]) }}>{{ $status->label() }}</span>
