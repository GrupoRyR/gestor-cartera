@use('App\Modules\Asesores\Domain\Client\InvoiceAging')
@use('App\Modules\Asesores\Domain\Format\Percent')
@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')
@use('Illuminate\Support\Facades\Lang')

{{--
    GET /asesores/clientes/{client} (asesores.clientes.show): the client
    file. $file holds what the Core contracts returned for this client: the
    detail, the position worked out from its open documents, the documents,
    the commitments and the log. $canManage says whether the user is the
    advisor who writes commitments and notes for the client.
--}}
@php
    $detail = $file->detail;
    $summary = $detail->summary;
    $position = $file->position;
    $overduePercent = $position->balance > 0 ? $position->overdue / $position->balance * 100 : 0.0;

    $description = __('asesores::ficha.descripcion', [
        'asesor' => $summary->advisor?->name ?? __('asesores::ficha.sin_asesor'),
        'condicion' => $detail->paymentTerms ?? __('asesores::ficha.sin_condicion'),
    ]);

    $creditHint = match (true) {
        ! $position->hasCredit() => __('asesores::ficha.kpis.sin_cupo'),
        $position->exceeded() > 0 => __('asesores::ficha.kpis.excedido', ['valor' => Money::format($position->exceeded())]),
        default => __('asesores::ficha.kpis.disponible', ['valor' => Money::format($position->available())]),
    };

    $invoiceColumns = [
        ['key' => 'documento', 'label' => __('asesores::ficha.facturas.columnas.documento')],
        ['key' => 'emision', 'label' => __('asesores::ficha.facturas.columnas.emision')],
        ['key' => 'vence', 'label' => __('asesores::ficha.facturas.columnas.vence')],
        ['key' => 'estado', 'label' => __('asesores::ficha.facturas.columnas.estado')],
        ['key' => 'valor', 'label' => __('asesores::ficha.facturas.columnas.valor'), 'align' => 'end'],
        ['key' => 'saldo', 'label' => __('asesores::ficha.facturas.columnas.saldo'), 'align' => 'end'],
        ['key' => 'edad', 'label' => __('asesores::ficha.facturas.columnas.edad')],
    ];

    $commitmentTone = fn (CommitmentStatus $status): string => match ($status) {
        CommitmentStatus::Pending => 'info',
        CommitmentStatus::Fulfilled => 'success',
        CommitmentStatus::Broken => 'danger',
        CommitmentStatus::Cancelled => 'neutral',
    };

    $ageTone = fn (string $key): string => match ($key) {
        'not_due' => 'neutral',
        'over_90' => 'danger',
        default => 'warning',
    };
@endphp

<x-layouts.app :title="$summary->name">
    <x-ui.page-header
        :title="$summary->name"
        :context="__('asesores::ficha.contexto', ['codigo' => $summary->code, 'nit' => $summary->taxId])"
        :description="$description"
    >
        <x-slot:actions>
            <x-ui.badge-state :state="$summary->status" />
            @if ($canManage)
                <x-ui.button icon="plus" data-modal-open="nuevo-compromiso">{{ __('asesores::compromisos.nuevo.boton') }}</x-ui.button>
            @endif
            <x-ui.link-button :href="route('asesores.clientes.index')" variant="secondary" icon="chevron-left">{{ __('asesores::ficha.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-6 grid gap-6">
        @if ($position->exceeded() > 0)
            <x-ui.alert tone="warning" :title="__('asesores::ficha.cupo_excedido.titulo')">
                {{ __('asesores::ficha.cupo_excedido.detalle', ['exceso' => Money::format($position->exceeded())]) }}
            </x-ui.alert>
        @endif

        <section aria-label="{{ __('asesores::ficha.kpis.saldo') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat
                :label="__('asesores::ficha.kpis.saldo')"
                :value="Money::format($position->balance)"
                :hint="trans_choice('asesores::ficha.kpis.documentos', count($file->invoices), ['cantidad' => count($file->invoices)])"
            />
            <x-ui.stat :label="__('asesores::ficha.kpis.sin_vencer')" :value="Money::format($position->notDue)" tone="success" />
            <x-ui.stat
                :label="__('asesores::ficha.kpis.vencido')"
                :value="Money::format($position->overdue)"
                :hint="__('asesores::ficha.kpis.del_total', ['porcentaje' => Percent::format($overduePercent)])"
                :tone="$position->overdue > 0 ? 'warning' : 'neutral'"
            />
            <x-ui.stat
                :label="__('asesores::ficha.kpis.cupo')"
                :value="$position->hasCredit() ? Money::format($position->creditLimit) : null"
                :hint="$creditHint"
                :tone="$position->exceeded() > 0 ? 'warning' : 'neutral'"
            />
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('asesores::ficha.edades.titulo')">
                <x-asesores::aging-bars :aging="$position->aging" />
            </x-ui.card>

            <x-ui.card :title="__('asesores::ficha.contactos.titulo')" :description="__('asesores::ficha.contactos.ayuda')">
                @if ($detail->contacts === [])
                    <p class="text-sm text-fg-soft">{{ __('asesores::ficha.contactos.vacio') }}</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($detail->contacts as $contact)
                            <li class="grid gap-0.5 py-3 first:pt-0 last:pb-0">
                                <span class="flex flex-wrap items-center gap-2 font-semibold">
                                    {{ $contact->name ?? __('asesores::ficha.contactos.sin_nombre') }}
                                    @if ($contact->isPrimary)
                                        <x-ui.chip tone="info" :dot="false">{{ __('asesores::ficha.contactos.principal') }}</x-ui.chip>
                                    @endif
                                </span>
                                @if (filled($contact->position))
                                    <span class="text-sm text-fg-muted">{{ $contact->position }}</span>
                                @endif
                                @if (filled($contact->email))
                                    <a href="mailto:{{ $contact->email }}" class="inline-flex min-h-touch items-center text-sm text-brand-ink underline-offset-4 hover:underline [overflow-wrap:anywhere]">{{ $contact->email }}</a>
                                @endif
                                @if (filled($contact->phone))
                                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $contact->phone) }}" class="inline-flex min-h-touch items-center text-sm text-brand-ink underline-offset-4 hover:underline">{{ $contact->phone }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card :title="__('asesores::ficha.facturas.titulo')">
            @if ($file->invoices === [])
                <p class="text-sm text-fg-soft">{{ __('asesores::ficha.facturas.vacio') }}</p>
            @else
                <x-ui.table :caption="__('asesores::ficha.facturas.tabla')" :caption-hidden="true" :columns="$invoiceColumns">
                    @foreach ($file->invoices as $invoice)
                        @php
                            $typeKey = 'asesores::ficha.facturas.tipos.'.$invoice->documentType;
                            $ageKey = InvoiceAging::key($invoice->daysOverdue);
                        @endphp
                        <tr>
                            <td>
                                <span class="block font-semibold">{{ $invoice->documentNumber }}</span>
                                <span class="block text-xs text-fg-muted">{{ Lang::has($typeKey) ? __($typeKey) : $invoice->documentType }}</span>
                            </td>
                            <td><x-ui.date :value="$invoice->issuedAt" /></td>
                            <td><x-ui.date :value="$invoice->dueAt" /></td>
                            <td>
                                @if ($invoice->daysOverdue > 0)
                                    {{ trans_choice('asesores::ficha.facturas.dias.vencida', $invoice->daysOverdue, ['dias' => $invoice->daysOverdue]) }}
                                @elseif ($invoice->daysOverdue === 0)
                                    {{ __('asesores::ficha.facturas.dias.hoy') }}
                                @else
                                    {{ trans_choice('asesores::ficha.facturas.dias.por_vencer', abs($invoice->daysOverdue), ['dias' => abs($invoice->daysOverdue)]) }}
                                @endif
                            </td>
                            <td data-align="end"><x-ui.money :value="$invoice->amount" /></td>
                            <td data-align="end"><x-ui.money :value="$invoice->balance" class="font-semibold" /></td>
                            <td><x-ui.chip :tone="$ageTone($ageKey)" :dot="false">{{ __('asesores::comun.edades.'.$ageKey) }}</x-ui.chip></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('asesores::ficha.compromisos.titulo')" :description="__('asesores::ficha.compromisos.ayuda')">
                @if ($file->commitments === [])
                    <p class="text-sm text-fg-soft">{{ __('asesores::ficha.compromisos.vacio') }}</p>
                @else
                    <ul class="divide-y divide-line" data-part="commitments">
                        @foreach ($file->commitments as $commitment)
                            <li class="grid gap-1.5 py-3 first:pt-0 last:pb-0">
                                <span class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <x-ui.money :value="$commitment->amount" class="font-semibold" />
                                    <x-ui.chip :tone="$commitmentTone($commitment->status)">{{ $commitment->status->label() }}</x-ui.chip>
                                </span>
                                <span class="text-sm text-fg-soft">{{ __('asesores::ficha.compromisos.fecha') }}: <x-ui.date :value="$commitment->dueDate" /></span>
                                @if (filled($commitment->notes))
                                    <span class="text-sm whitespace-pre-line [overflow-wrap:anywhere]">{{ $commitment->notes }}</span>
                                @endif
                                <span class="text-xs text-fg-muted">
                                    {{ __('asesores::ficha.compromisos.por', ['nombre' => $commitment->createdBy->name, 'fecha' => DateFormat::date($commitment->createdAt)]) }}
                                    @if ($commitment->fulfilledAt !== null)
                                        · {{ __('asesores::ficha.compromisos.cumplido_el', ['fecha' => DateFormat::date($commitment->fulfilledAt)]) }}
                                    @endif
                                </span>
                                <a href="{{ route('asesores.compromisos.show', $commitment->id) }}" class="inline-flex min-h-touch items-center text-sm font-semibold text-brand-ink underline-offset-4 hover:underline">
                                    @can('fulfill', $commitment){{ __('asesores::compromisos.ver') }}@else{{ __('asesores::compromisos.detalle') }}@endcan
                                    <span data-visually-hidden>&nbsp;{{ Money::format($commitment->amount) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('asesores::ficha.bitacora.titulo')" :description="__('asesores::ficha.bitacora.ayuda')">
                @if ($canAnnotate)
                    <x-slot:actions>
                        <x-ui.button variant="secondary" icon="plus" data-modal-open="nueva-nota">{{ __('asesores::ficha.bitacora.nota.boton') }}</x-ui.button>
                    </x-slot:actions>
                @endif

                @if ($file->activity === [])
                    <p class="text-sm text-fg-soft">{{ __('asesores::ficha.bitacora.vacio') }}</p>
                @else
                    <ol class="divide-y divide-line" data-part="activity">
                        @foreach ($file->activity as $entry)
                            <li class="grid gap-1 py-3 first:pt-0 last:pb-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <x-ui.chip :dot="false">{{ $entry->type->label() }}</x-ui.chip>
                                    <span class="font-semibold [overflow-wrap:anywhere]">{{ $entry->summary }}</span>
                                </span>
                                @if (filled($entry->details))
                                    <span class="text-sm whitespace-pre-line [overflow-wrap:anywhere]">{{ $entry->details }}</span>
                                @endif
                                @if (filled($entry->result))
                                    <span class="text-sm text-fg-soft">{{ __('asesores::ficha.bitacora.resultado', ['resultado' => $entry->result]) }}</span>
                                @endif
                                <span class="text-xs text-fg-muted">{{ __('asesores::ficha.bitacora.por', ['nombre' => $entry->user?->name ?? __('asesores::ficha.bitacora.plataforma'), 'fecha' => DateFormat::dateTime($entry->occurredAt)]) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($canAnnotate)
        <x-ui.modal id="nueva-nota" :title="__('asesores::ficha.bitacora.nota.titulo')" :open="old('formulario') === 'nueva-nota' && $errors->any()">
            <form id="form-nueva-nota" method="POST" action="{{ route('asesores.notas.store', $summary->id) }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="formulario" value="nueva-nota">

                <x-ui.textarea name="nota" :label="__('asesores::ficha.bitacora.nota.etiqueta')" :hint="__('asesores::ficha.bitacora.nota.ayuda')" :rows="5" required />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" data-modal-close>{{ __('asesores::ficha.bitacora.nota.cancelar') }}</x-ui.button>
                <x-ui.button type="submit" form="form-nueva-nota" icon="check">{{ __('asesores::ficha.bitacora.nota.guardar') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($canManage)
        @php($newFormFailed = old('formulario') === 'nuevo-compromiso' && $errors->any())

        <x-ui.modal id="nuevo-compromiso" :title="__('asesores::compromisos.nuevo.titulo')" :open="$newFormFailed">
            <form id="form-nuevo-compromiso" method="POST" action="{{ route('asesores.compromisos.store', $summary->id) }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="formulario" value="nuevo-compromiso">

                <x-ui.input name="fecha" type="date" :label="__('asesores::compromisos.campos.fecha.etiqueta')" :hint="__('asesores::compromisos.campos.fecha.ayuda')" :min="$today->format('Y-m-d')" required />
                <x-ui.input
                    name="valor"
                    :label="__('asesores::compromisos.campos.valor.etiqueta')"
                    :hint="$position->overdue > 0 ? __('asesores::compromisos.campos.valor.ayuda_vencido', ['vencido' => Money::format($position->overdue)]) : __('asesores::compromisos.campos.valor.ayuda')"
                    prefix="$"
                    inputmode="decimal"
                    autocomplete="off"
                    required
                />
                <x-ui.textarea name="notas" :label="__('asesores::compromisos.campos.notas.etiqueta')" :hint="__('asesores::compromisos.campos.notas.ayuda')" :rows="3" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" data-modal-close>{{ __('asesores::compromisos.nuevo.cancelar') }}</x-ui.button>
                <x-ui.button type="submit" form="form-nuevo-compromiso" icon="check">{{ __('asesores::compromisos.nuevo.guardar') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-layouts.app>
