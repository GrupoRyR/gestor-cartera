@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /asesores/compromisos/{commitment} (asesores.compromisos.show): one
    commitment. The advisor who owns the client changes the date and amount
    while it is pending, marks it fulfilled (also when it is late) or
    cancels it with a reason; everybody else who can open it, such as a sales
    manager, reads it. $client is null when the commitment belongs to a
    company other than the one the user is working in.
--}}
@php
    $tone = match ($commitment->status) {
        CommitmentStatus::Pending => 'info',
        CommitmentStatus::Fulfilled => 'success',
        CommitmentStatus::Broken => 'danger',
        CommitmentStatus::Cancelled => 'neutral',
    };

    $backUrl = $client !== null ? route('asesores.clientes.show', $client->summary->id) : route('asesores.clientes.index');
    $writes = auth()->user()?->can('manage', $commitment) ?? false;
    $closed = in_array($commitment->status, [CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled], true);

    // The amount as the form reads it: "2.500.000", with cents only when there are some.
    $amountText = number_format($commitment->amount, fmod($commitment->amount, 1.0) === 0.0 ? 0 : 2, ',', '.');
@endphp

<x-layouts.app :title="__('asesores::compromisos.show.titulo')">
    <x-ui.page-header :title="__('asesores::compromisos.show.titulo')" :context="$commitment->clientName">
        <x-slot:actions>
            <x-ui.link-button :href="$backUrl" variant="secondary" icon="chevron-left">{{ __('asesores::compromisos.show.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-6 grid gap-6">
        <x-ui.card>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-fg-muted">{{ __('asesores::compromisos.show.cliente') }}</dt>
                    <dd class="font-semibold [overflow-wrap:anywhere]">
                        @if ($client !== null)
                            <a href="{{ $backUrl }}" class="text-brand-ink underline-offset-4 hover:underline">{{ $commitment->clientName }}</a>
                        @else
                            {{ $commitment->clientName }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-muted">{{ __('asesores::compromisos.show.valor') }}</dt>
                    <dd class="font-semibold"><x-ui.money :value="$commitment->amount" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-muted">{{ __('asesores::compromisos.show.fecha') }}</dt>
                    <dd class="font-semibold"><x-ui.date :value="$commitment->dueDate" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-muted">{{ __('asesores::compromisos.show.estado') }}</dt>
                    <dd><x-ui.chip :tone="$tone">{{ $commitment->status->label() }}</x-ui.chip></dd>
                </div>
            </dl>

            @if (filled($commitment->notes))
                <p class="mt-4 text-sm whitespace-pre-line [overflow-wrap:anywhere]">{{ $commitment->notes }}</p>
            @endif

            <p class="mt-4 text-xs text-fg-muted">
                {{ __('asesores::compromisos.show.registrado', ['nombre' => $commitment->createdBy->name, 'fecha' => DateFormat::date($commitment->createdAt)]) }}
                @if ($commitment->fulfilledAt !== null)
                    · {{ __('asesores::compromisos.show.cumplido', ['fecha' => DateFormat::date($commitment->fulfilledAt)]) }}
                @endif
            </p>
        </x-ui.card>

        @if (! $writes)
            <x-ui.alert tone="info">{{ __('asesores::compromisos.show.solo_lectura') }}</x-ui.alert>
        @elseif ($closed)
            <x-ui.alert tone="info">{{ __('asesores::compromisos.show.cerrado') }}</x-ui.alert>
        @endif

        @can('update', $commitment)
            <x-ui.card :title="__('asesores::compromisos.show.editar.titulo')" :description="__('asesores::compromisos.show.editar.ayuda')">
                <form method="POST" action="{{ route('asesores.compromisos.update', $commitment->id) }}" class="grid max-w-xl gap-4">
                    @csrf
                    @method('PUT')

                    <x-ui.input name="fecha" type="date" :label="__('asesores::compromisos.campos.fecha.etiqueta')" :hint="__('asesores::compromisos.campos.fecha.ayuda')" :value="$commitment->dueDate->format('Y-m-d')" :min="$today->format('Y-m-d')" required />
                    <x-ui.input name="valor" :label="__('asesores::compromisos.campos.valor.etiqueta')" :hint="__('asesores::compromisos.campos.valor.ayuda')" :value="$amountText" prefix="$" inputmode="decimal" autocomplete="off" required />
                    <x-ui.textarea name="notas" :label="__('asesores::compromisos.campos.notas.etiqueta')" :hint="__('asesores::compromisos.campos.notas.ayuda')" :value="$commitment->notes" :rows="3" />

                    <div><x-ui.button type="submit" icon="check" class="max-sm:w-full max-sm:justify-center">{{ __('asesores::compromisos.show.editar.guardar') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan

        @can('fulfill', $commitment)
            <x-ui.card :title="__('asesores::compromisos.show.cumplir.titulo')" :description="__('asesores::compromisos.show.cumplir.ayuda')">
                <form method="POST" action="{{ route('asesores.compromisos.fulfill', $commitment->id) }}" class="grid max-w-xl gap-4">
                    @csrf

                    <x-ui.input name="nota" :label="__('asesores::compromisos.campos.nota.etiqueta')" :hint="__('asesores::compromisos.campos.nota.ayuda')" />

                    <div><x-ui.button type="submit" icon="calendar-check" class="max-sm:w-full max-sm:justify-center">{{ __('asesores::compromisos.show.cumplir.boton') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan

        @can('cancel', $commitment)
            <x-ui.card :title="__('asesores::compromisos.show.anular.titulo')" :description="__('asesores::compromisos.show.anular.ayuda')">
                <form method="POST" action="{{ route('asesores.compromisos.cancel', $commitment->id) }}" class="grid max-w-xl gap-4">
                    @csrf

                    <x-ui.input name="motivo" :label="__('asesores::compromisos.campos.motivo.etiqueta')" :hint="__('asesores::compromisos.campos.motivo.ayuda')" required />

                    <div><x-ui.button type="submit" variant="danger" icon="close" class="max-sm:w-full max-sm:justify-center">{{ __('asesores::compromisos.show.anular.boton') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan
    </div>
</x-layouts.app>
