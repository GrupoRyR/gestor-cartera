{{--
    GET /soportes/nuevo?cliente={id} (soportes.create), step 2: the upload
    form for one client. $client: ClientSummary; $documents: the client's
    overdue invoices grouped by document number (list of non-empty lists of
    Invoice, oldest due date first; more than one when the document has
    split payment terms); $methods: PaymentMethod value => label; $today:
    "Y-m-d", the latest payment date. Posts to soportes.store, which comes
    back here with the errors and the input when something is wrong.
--}}
@php
    $columns = [
        ['key' => 'document', 'label' => __('soportes::carga.formulario.facturas.columnas.documento')],
        ['key' => 'type', 'label' => __('soportes::carga.formulario.facturas.columnas.tipo')],
        ['key' => 'due', 'label' => __('soportes::carga.formulario.facturas.columnas.vencimiento')],
        ['key' => 'days', 'label' => __('soportes::carga.formulario.facturas.columnas.dias'), 'align' => 'end'],
        ['key' => 'balance', 'label' => __('soportes::carga.formulario.facturas.columnas.saldo'), 'align' => 'end'],
    ];
    $maxFiles = (int) config('soportes.max_files');
    $fileErrors = collect($errors->get('archivos.*'))->flatten()->unique()->values()->all();
@endphp

<x-layouts.app :title="__('soportes::carga.titulo')">
    <x-ui.page-header :context="__('soportes::carga.contexto')" :title="__('soportes::carga.titulo')" :description="__('soportes::carga.formulario.descripcion')">
        <x-slot:actions>
            <x-ui.link-button :href="route('soportes.create')" variant="ghost">{{ __('soportes::carga.formulario.cambiar') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        <x-ui.card :title="$client->name" :description="__('soportes::carga.formulario.cliente', ['nit' => $client->taxId, 'codigo' => $client->code, 'empresa' => $client->company->label()])" />

        @if ($errors->has('cliente'))
            <x-ui.alert tone="danger">{{ $errors->first('cliente') }}</x-ui.alert>
        @endif

        @if ($documents === [])
            <x-ui.card>
                <x-ui.empty icon="check" :title="__('soportes::carga.formulario.facturas.vacio.titulo')" :description="__('soportes::carga.formulario.facturas.vacio.detalle')" />
            </x-ui.card>
        @else
            <form method="POST" action="{{ route('soportes.store') }}" enctype="multipart/form-data" class="flex flex-col gap-6">
                @csrf
                <input type="hidden" name="cliente" value="{{ $client->id }}">

                <x-ui.card :title="__('soportes::carga.formulario.facturas.titulo')" :description="__('soportes::carga.formulario.facturas.descripcion')">
                    @if ($errors->has('facturas') || $errors->has('facturas.*'))
                        <x-ui.alert tone="danger">{{ $errors->first('facturas') ?: $errors->first('facturas.*') }}</x-ui.alert>
                    @endif

                    <x-ui.table :caption="__('soportes::carga.formulario.facturas.tabla')" :caption-hidden="true" :columns="$columns">
                        @foreach ($documents as $installments)
                            @php($first = $installments[0])
                            <tr data-document="{{ $first->documentNumber }}">
                                <td class="min-w-44">
                                    <x-ui.checkbox
                                        name="facturas[]"
                                        :value="$first->documentNumber"
                                        :label="$first->documentNumber"
                                        :hint="count($installments) > 1 ? trans_choice('soportes::carga.formulario.facturas.cuotas', count($installments)) : null"
                                    />
                                </td>
                                <td>{{ $first->documentType }}</td>
                                <td><x-ui.date :value="$first->dueAt" /></td>
                                <td data-align="end">{{ number_format($first->daysOverdue, 0, ',', '.') }}</td>
                                <td data-align="end"><x-ui.money :value="array_sum(array_map(fn ($invoice) => $invoice->balance, $installments))" /></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>

                <x-ui.card :title="__('soportes::carga.formulario.pago.titulo')">
                    <div class="flex max-w-xl flex-col gap-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-ui.input
                                name="valor"
                                type="number"
                                prefix="$"
                                :label="__('soportes::carga.formulario.valor')"
                                :hint="__('soportes::carga.formulario.valor_ayuda')"
                                :required="true"
                                min="0.01"
                                step="0.01"
                                inputmode="decimal"
                                autocomplete="off"
                            />
                            <x-ui.input name="fecha_pago" type="date" :label="__('soportes::carga.formulario.fecha')" :required="true" :max="$today" />
                        </div>

                        <x-ui.select name="metodo" :label="__('soportes::carga.formulario.metodo')" :options="$methods" :placeholder="__('soportes::carga.formulario.metodo_vacio')" />

                        <x-ui.textarea name="nota" :label="__('soportes::carga.formulario.nota')" :hint="__('soportes::carga.formulario.nota_ayuda')" :rows="3" maxlength="1000" />

                        <x-ui.input
                            name="archivos[]"
                            type="file"
                            :label="__('soportes::carga.formulario.archivos')"
                            :hint="__('soportes::carga.formulario.archivos_ayuda', ['max' => $maxFiles, 'mb' => intdiv((int) config('soportes.max_file_kilobytes'), 1024)])"
                            :required="true"
                            multiple
                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        />

                        @if ($fileErrors !== [])
                            <x-ui.alert tone="danger">
                                @foreach ($fileErrors as $message)
                                    <p>{{ $message }}</p>
                                @endforeach
                            </x-ui.alert>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit" icon="upload">{{ __('soportes::carga.formulario.enviar') }}</x-ui.button>
                            <x-ui.link-button :href="route('soportes.create')" variant="ghost">{{ __('soportes::carga.formulario.cancelar') }}</x-ui.link-button>
                        </div>
                    </div>
                </x-ui.card>
            </form>
        @endif
    </div>
</x-layouts.app>
