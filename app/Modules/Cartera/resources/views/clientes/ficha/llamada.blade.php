@use('App\Modules\Cartera\Domain\Calls\CallAgreement')
@use('App\Modules\Cartera\Domain\Calls\CallChannel')
@use('App\Modules\Cartera\Domain\Calls\CallOutcome')
@use('App\Modules\Cartera\Domain\Commitments\CommitmentWriting')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')
@use('App\View\Components\Support\FieldStyle')

{{--
    The "Registrar llamada" form (cartera.llamadas.store), for the portfolio
    team (gestion.blade.php includes it inside @can('cartera.manage')).

    The number and the person are picked from the client's contacts, the
    primary one first, or typed in the field next to them: a typed one wins,
    so the form needs no script. "Compromiso de pago" is offered only to whoever
    writes the commitments of the client's company (CommitmentWriting and
    cartera.commitments.write). The open invoices are those the file already
    loaded. The form opens again with what was typed when it failed, and on
    arrival with ?registrar-llamada=1 (the follow-up notice links there).
    $file, $detail, $client.
--}}
@php
    $contacts = collect($detail->contacts)->sortByDesc(fn ($contact): bool => $contact->isPrimary)->values();

    $phones = [];
    $people = [];

    foreach ($contacts as $contact) {
        $phone = trim((string) $contact->phone);
        $name = trim((string) $contact->name);

        if ($phone !== '' && ! isset($phones[$phone])) {
            $phones[$phone] = $name === '' ? $phone : "{$phone} · {$name}";
        }

        if ($name !== '' && ! isset($people[$name])) {
            $people[$name] = $name;
        }
    }

    $agreements = CallAgreement::options();
    $offersCommitment = CommitmentWriting::byCollector($client->company) && auth()->user()?->can('cartera.commitments.write');

    if (! $offersCommitment) {
        unset($agreements[CallAgreement::PaymentCommitment->value]);
    }

    $now = now('America/Bogota');
@endphp

<x-ui.modal id="gestion-llamada" :title="__('cartera::llamadas.formulario.titulo')" :open="$errors->has('llamada.*') || request()->boolean('registrar-llamada')">
    <form id="gestion-llamada-form" method="POST" action="{{ route('cartera.llamadas.store', $client->id) }}" class="grid gap-4">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.input
                name="llamada[ocurrio]"
                type="datetime-local"
                :label="__('cartera::llamadas.formulario.ocurrio')"
                :hint="__('cartera::llamadas.formulario.ocurrio_ayuda')"
                :value="$now->format('Y-m-d\TH:i')"
                :max="$now->format('Y-m-d\TH:i')"
                :required="true"
            />
            <x-ui.select
                name="llamada[canal]"
                :label="__('cartera::llamadas.formulario.canal')"
                :options="CallChannel::options()"
                :value="CallChannel::Call"
                :required="true"
            />
        </div>

        @if ($phones === [])
            <x-ui.input name="llamada[telefono]" type="tel" :label="__('cartera::llamadas.formulario.telefono_solo')" maxlength="40" :required="true" />
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select
                    name="llamada[numero]"
                    :label="__('cartera::llamadas.formulario.numero')"
                    :options="$phones"
                    :value="array_key_first($phones)"
                    :placeholder="__('cartera::llamadas.formulario.otro_numero')"
                />
                <x-ui.input
                    name="llamada[telefono]"
                    type="tel"
                    :label="__('cartera::llamadas.formulario.otro_numero')"
                    :hint="__('cartera::llamadas.formulario.telefono_ayuda')"
                    maxlength="40"
                />
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            @if ($people !== [])
                <x-ui.select
                    name="llamada[persona]"
                    :label="__('cartera::llamadas.formulario.persona')"
                    :options="$people"
                    :value="array_key_first($people)"
                    :placeholder="__('cartera::llamadas.formulario.otra_persona')"
                />
            @endif
            <x-ui.input
                name="llamada[contacto]"
                :label="$people === [] ? __('cartera::llamadas.formulario.persona') : __('cartera::llamadas.formulario.otra_persona')"
                :hint="__('cartera::llamadas.formulario.contacto_ayuda')"
                maxlength="150"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select
                name="llamada[resultado]"
                :label="__('cartera::llamadas.formulario.resultado')"
                :options="CallOutcome::options()"
                :placeholder="__('cartera::llamadas.formulario.elige_resultado')"
                :required="true"
            />
            <x-ui.select
                name="llamada[acuerdo]"
                :label="__('cartera::llamadas.formulario.acuerdo')"
                :options="$agreements"
                :value="CallAgreement::None"
                :hint="__($offersCommitment ? 'cartera::llamadas.formulario.acuerdo_ayuda_compromiso' : 'cartera::llamadas.formulario.acuerdo_ayuda')"
                :required="true"
            />
        </div>

        <x-ui.input
            name="llamada[seguimiento]"
            type="date"
            :label="__('cartera::llamadas.formulario.seguimiento')"
            :hint="__('cartera::llamadas.formulario.seguimiento_ayuda')"
            :min="$now->toDateString()"
        />

        <x-ui.textarea name="llamada[notas]" :label="__('cartera::llamadas.formulario.notas')" :hint="__('cartera::llamadas.formulario.notas_ayuda')" :rows="3" maxlength="2000" />

        <fieldset class="{{ FieldStyle::FIELD }}" aria-describedby="llamada-facturas-hint" @if ($errors->has('llamada.facturas')) data-invalid @endif>
            <legend class="{{ FieldStyle::LABEL }}">{{ __('cartera::llamadas.formulario.facturas') }}</legend>

            @if ($file->invoices === [])
                <p id="llamada-facturas-hint" class="{{ FieldStyle::HINT }}">{{ __('cartera::llamadas.formulario.sin_facturas') }}</p>
            @else
                <p id="llamada-facturas-hint" class="{{ FieldStyle::HINT }}">{{ __('cartera::llamadas.formulario.facturas_ayuda') }}</p>

                @foreach ($file->invoices as $invoice)
                    <x-ui.checkbox
                        :name="'llamada[facturas]['.$invoice->id.']'"
                        :value="$invoice->id"
                        :label="__('cartera::llamadas.factura', [
                            'tipo' => $invoice->documentType,
                            'numero' => $invoice->documentNumber,
                            'fecha' => DateFormat::date($invoice->dueAt),
                            'saldo' => Money::format($invoice->balance),
                        ])"
                    />
                @endforeach
            @endif

            @if ($errors->has('llamada.facturas'))
                <p data-part="error" class="{{ FieldStyle::ERROR }}">{{ $errors->first('llamada.facturas') }}</p>
            @endif
        </fieldset>
    </form>

    <x-slot:footer>
        <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::llamadas.formulario.cancelar') }}</x-ui.button>
        <x-ui.button type="submit" form="gestion-llamada-form">{{ __('cartera::llamadas.formulario.guardar') }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
