{{-- Open invoices of the client, the oldest due date first. $file. --}}
@php
    $invoiceColumns = [
        ['key' => 'document', 'label' => __('cartera::ficha.facturas.columnas.documento')],
        ['key' => 'issued', 'label' => __('cartera::ficha.facturas.columnas.emision')],
        ['key' => 'due', 'label' => __('cartera::ficha.facturas.columnas.vence')],
        ['key' => 'days', 'label' => __('cartera::ficha.facturas.columnas.dias')],
        ['key' => 'amount', 'label' => __('cartera::ficha.facturas.columnas.valor'), 'align' => 'end'],
        ['key' => 'balance', 'label' => __('cartera::ficha.facturas.columnas.saldo'), 'align' => 'end'],
    ];
@endphp

<x-ui.card :title="__('cartera::ficha.facturas.titulo').' ('.count($file->invoices).')'" :description="__('cartera::ficha.facturas.descripcion')">
    @if ($file->invoices === [])
        <x-ui.empty icon="success" :title="__('cartera::ficha.facturas.vacio.titulo')" :description="__('cartera::ficha.facturas.vacio.detalle')" />
    @else
        <x-ui.table :caption="__('cartera::ficha.facturas.tabla')" :caption-hidden="true" :columns="$invoiceColumns">
            @foreach ($file->invoices as $invoice)
                <tr>
                    <td>{{ $invoice->documentType }} {{ $invoice->documentNumber }}</td>
                    <td><x-ui.date :value="$invoice->issuedAt" /></td>
                    <td><x-ui.date :value="$invoice->dueAt" /></td>
                    <td>
                        @if ($invoice->daysOverdue > 0)
                            <x-ui.chip tone="danger">{{ trans_choice('cartera::ficha.facturas.mora', $invoice->daysOverdue) }}</x-ui.chip>
                        @elseif ($invoice->daysOverdue === 0)
                            <x-ui.chip tone="warning">{{ __('cartera::ficha.facturas.vence_hoy') }}</x-ui.chip>
                        @else
                            {{ trans_choice('cartera::ficha.facturas.por_vencer', abs($invoice->daysOverdue)) }}
                        @endif
                    </td>
                    <td data-align="end"><x-ui.money :value="$invoice->amount" /></td>
                    <td data-align="end"><x-ui.money :value="$invoice->balance" /></td>
                </tr>
            @endforeach

            <x-slot:footer>
                <tr>
                    <th scope="row" colspan="5">{{ __('cartera::ficha.facturas.total') }}</th>
                    <td data-align="end"><x-ui.money :value="$file->invoiceBalance()" /></td>
                </tr>
            </x-slot:footer>
        </x-ui.table>
    @endif
</x-ui.card>
