@use('App\Modules\Soportes\Domain\ReceiptStatus')

{{--
    GET /soportes/{soporte} (soportes.show): one receipt. $receipt: Receipt
    with invoices (oldest due date first), files, uploader and reviewer.
    The invoices are the copies taken at upload; the files link to the
    authorized download (soportes.files.download).
--}}
@php
    $columns = [
        ['key' => 'document', 'label' => __('soportes::detalle.facturas.columnas.documento')],
        ['key' => 'type', 'label' => __('soportes::detalle.facturas.columnas.tipo')],
        ['key' => 'due', 'label' => __('soportes::detalle.facturas.columnas.vencimiento')],
        ['key' => 'days', 'label' => __('soportes::detalle.facturas.columnas.dias'), 'align' => 'end'],
        ['key' => 'balance', 'label' => __('soportes::detalle.facturas.columnas.saldo'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="$receipt->client_name">
    <x-ui.page-header :context="__('soportes::detalle.contexto')" :title="$receipt->client_name">
        <x-slot:actions>
            <x-ui.link-button :href="route('soportes.index')" variant="secondary" icon="chevron-left">{{ __('soportes::detalle.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        <div class="grid items-start gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('soportes::detalle.datos.titulo')">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.cliente') }}</dt>
                        <dd class="font-semibold break-words">{{ __('soportes::detalle.datos.cliente_valor', ['nombre' => $receipt->client_name, 'codigo' => $receipt->client_code]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.empresa') }}</dt>
                        <dd class="font-semibold">{{ $receipt->company->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.valor') }}</dt>
                        <dd class="font-semibold"><x-ui.money :value="$receipt->amount" /></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.fecha') }}</dt>
                        <dd class="font-semibold"><x-ui.date :value="$receipt->paid_at" /></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.metodo') }}</dt>
                        <dd class="font-semibold">{{ $receipt->method?->label() ?? __('soportes::detalle.datos.sin_metodo') }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.nota') }}</dt>
                        <dd class="break-words">{{ $receipt->note ?? __('soportes::detalle.datos.sin_nota') }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.datos.cargado_por') }}</dt>
                        <dd>{{ $receipt->uploader->name }} · <x-ui.date :value="$receipt->created_at" :time="true" /></dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card :title="__('soportes::detalle.estado.titulo')">
                <dl class="grid gap-x-6 gap-y-3">
                    <div>
                        <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.estado.estado') }}</dt>
                        <dd><x-ui.chip :tone="$receipt->status->tone()">{{ $receipt->status->label() }}</x-ui.chip></dd>
                    </div>
                    @if ($receipt->status->isFinal())
                        <div>
                            <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.estado.revisado_por') }}</dt>
                            <dd>{{ $receipt->reviewer?->name ?? '—' }} · <x-ui.date :value="$receipt->reviewed_at" :time="true" /></dd>
                        </div>
                    @endif
                    @if ($receipt->status === ReceiptStatus::Applied)
                        <div>
                            <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.estado.recibo') }}</dt>
                            <dd class="font-semibold break-words">{{ $receipt->receipt_number ?? __('soportes::detalle.estado.sin_recibo') }}</dd>
                        </div>
                    @endif
                    @if ($receipt->status === ReceiptStatus::Rejected)
                        <div>
                            <dt class="text-sm text-fg-muted">{{ __('soportes::detalle.estado.motivo') }}</dt>
                            <dd class="break-words">{{ $receipt->rejection_reason }}</dd>
                        </div>
                    @endif
                </dl>

                @if (! $receipt->status->isFinal())
                    <p class="mt-4 text-sm text-fg-muted">{{ __('soportes::detalle.estado.pendiente') }}</p>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card :title="__('soportes::detalle.facturas.titulo')" :description="__('soportes::detalle.facturas.descripcion')">
            <x-ui.table :caption="__('soportes::detalle.facturas.tabla')" :caption-hidden="true" :columns="$columns">
                @foreach ($receipt->invoices as $invoice)
                    <tr>
                        <td class="font-semibold">{{ $invoice->document_number }}</td>
                        <td>{{ $invoice->document_type }}</td>
                        <td><x-ui.date :value="$invoice->due_at" /></td>
                        <td data-align="end">{{ number_format($invoice->days_overdue, 0, ',', '.') }}</td>
                        <td data-align="end"><x-ui.money :value="$invoice->balance" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('soportes::detalle.archivos.titulo')">
            <ul class="flex flex-col gap-3">
                @foreach ($receipt->files as $file)
                    <li class="flex flex-wrap items-center gap-2">
                        <x-ui.icon name="download" />
                        <a
                            href="{{ route('soportes.files.download', ['soporte' => $receipt, 'archivo' => $file->id]) }}"
                            class="font-semibold break-words text-brand-ink underline underline-offset-4"
                            aria-label="{{ __('soportes::detalle.archivos.descargar', ['nombre' => $file->original_name]) }}"
                        >{{ $file->original_name }}</a>
                        <span class="text-sm text-fg-muted">{{ __('soportes::detalle.archivos.peso', ['kb' => number_format(max(1, (int) ceil($file->size / 1024)), 0, ',', '.')]) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</x-layouts.app>
