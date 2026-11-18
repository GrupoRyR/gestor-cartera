@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')
@use('App\Support\Formatting\Money')

{{--
    Status buttons of a payment commitment (<x-cartera::commitment-status-actions>)
    for whoever holds cartera.commitments.status: "Marcar cumplido" on an open
    one (pending or broken, a late payment still fulfils it) and "Marcar
    incumplido" on a pending one. Nothing on a closed one. The caller lays
    the buttons out. $commitment: Commitment; $clientName: for screen readers.
--}}
@props(['commitment', 'clientName'])

@php
    $about = ['cliente' => $clientName, 'valor' => Money::format($commitment->amount)];
@endphp

@if (in_array($commitment->status, [CommitmentStatus::Pending, CommitmentStatus::Broken], true))
    <form method="POST" action="{{ route('cartera.compromisos.cumplir', $commitment->id) }}">
        @csrf
        <x-ui.button type="submit" variant="secondary" icon="check">{{ __('cartera::compromisos.cumplir.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.cumplir.de', $about) }}</span></x-ui.button>
    </form>
@endif

@if ($commitment->status === CommitmentStatus::Pending)
    <form method="POST" action="{{ route('cartera.compromisos.incumplir', $commitment->id) }}">
        @csrf
        <x-ui.button type="submit" variant="ghost" icon="warning">{{ __('cartera::compromisos.incumplir.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.incumplir.de', $about) }}</span></x-ui.button>
    </form>
@endif
