@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')
@use('App\Support\Formatting\Money')

{{--
    Status buttons of a payment commitment (<x-cartera::commitment-status-actions>)
    for whoever holds cartera.commitments.status: "Marcar cumplido" and
    "Registrar abono" on an open one (pending or broken, a late payment
    still counts) and "Marcar incumplido" on a pending one. Nothing on a
    closed one. The caller lays the buttons out. "Registrar abono" opens
    this commitment's own modal, rendered here so the inbox and the client
    file both get it; it opens again after a failed submit. $commitment:
    Commitment; $clientName: for screen readers.
--}}
@props(['commitment', 'clientName'])

@php
    $about = ['cliente' => $clientName, 'valor' => Money::format($commitment->amount)];
    $isOpen = in_array($commitment->status, [CommitmentStatus::Pending, CommitmentStatus::Broken], true);
    $payment = "abono_{$commitment->id}";
@endphp

@if ($isOpen)
    <form method="POST" action="{{ route('cartera.compromisos.cumplir', $commitment->id) }}">
        @csrf
        <x-ui.button type="submit" variant="secondary" icon="check">{{ __('cartera::compromisos.cumplir.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.cumplir.de', $about) }}</span></x-ui.button>
    </form>

    <x-ui.button variant="ghost" icon="money" data-modal-open="compromiso-{{ $commitment->id }}-abono">{{ __('cartera::compromisos.abonar.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.abonar.de', $about) }}</span></x-ui.button>
@endif

@if ($commitment->status === CommitmentStatus::Pending)
    <form method="POST" action="{{ route('cartera.compromisos.incumplir', $commitment->id) }}">
        @csrf
        <x-ui.button type="submit" variant="ghost" icon="warning">{{ __('cartera::compromisos.incumplir.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.incumplir.de', $about) }}</span></x-ui.button>
    </form>
@endif

@if ($isOpen)
    {{-- The cell around it aligns to the end; the modal reads from the start. --}}
    <x-ui.modal id="compromiso-{{ $commitment->id }}-abono" :title="__('cartera::compromisos.abonar.titulo')" :open="$errors->has($payment.'.*') || old('form') === $payment" class="text-left">
        <form id="compromiso-{{ $commitment->id }}-abono-form" method="POST" action="{{ route('cartera.compromisos.abonar', $commitment->id) }}" class="grid gap-4">
            @csrf
            <input type="hidden" name="form" value="{{ $payment }}">
            <p>{{ __('cartera::compromisos.abonar.pregunta', $about) }}</p>
            <x-ui.money-input :name="$payment.'[valor]'" :label="__('cartera::compromisos.abonar.valor')" :hint="__('cartera::compromisos.abonar.valor_ayuda', $about)" :required="true" />
            <x-ui.textarea :name="$payment.'[nota]'" :label="__('cartera::compromisos.abonar.nota')" :hint="__('cartera::compromisos.abonar.nota_ayuda')" :rows="3" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::compromisos.cancelar') }}</x-ui.button>
            <x-ui.button type="submit" form="compromiso-{{ $commitment->id }}-abono-form">{{ __('cartera::compromisos.abonar.guardar') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif
