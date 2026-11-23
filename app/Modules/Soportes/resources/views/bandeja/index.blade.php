@use('App\Modules\Soportes\Domain\ReceiptStatus')

{{--
    GET /soportes (soportes.index): the receipts inbox. $filter:
    Application\Inbox\InboxFilter; $receipts: paginator of Receipt (with
    uploader and invoices_count) of the current tab; $counts: status value =>
    receipts in that tab with the same filters; $isGroup: name the company
    of each receipt; $reviews: the user reviews (soportes.review), otherwise
    the page is the advisor's "Mis soportes"; $canUpload: soportes.upload.
--}}
@php
    $rejectedTab = $filter->status === ReceiptStatus::Rejected;

    $columns = [
        ['key' => 'client', 'label' => __('soportes::bandeja.columnas.cliente')],
        ...($isGroup ? [['key' => 'company', 'label' => __('soportes::bandeja.columnas.empresa')]] : []),
        ['key' => 'amount', 'label' => __('soportes::bandeja.columnas.valor'), 'align' => 'end'],
        ['key' => 'paid_at', 'label' => __('soportes::bandeja.columnas.fecha')],
        ['key' => 'invoices', 'label' => __('soportes::bandeja.columnas.facturas'), 'align' => 'end'],
        ['key' => 'uploader', 'label' => __('soportes::bandeja.columnas.cargado_por')],
        ['key' => 'created_at', 'label' => __('soportes::bandeja.columnas.cargado')],
        ['key' => 'status', 'label' => __('soportes::bandeja.columnas.estado')],
        ...($rejectedTab ? [['key' => 'reason', 'label' => __('soportes::bandeja.columnas.motivo')]] : []),
    ];

    $tabs = array_map(fn (ReceiptStatus $status): array => [
        'key' => $status->value,
        'label' => __('soportes::bandeja.pestanas.'.$status->value),
        'href' => route('soportes.index', $filter->query($status)),
        'count' => $counts[$status->value],
    ], ReceiptStatus::cases());

    $title = $reviews ? __('soportes::bandeja.titulo') : __('soportes::bandeja.titulo_asesor');
    $clearUrl = route('soportes.index', $filter->status === ReceiptStatus::Pending ? [] : ['estado' => $filter->status->value]);
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :description="$reviews ? __('soportes::bandeja.descripcion') : __('soportes::bandeja.descripcion_asesor')">
        @if ($canUpload)
            <x-slot:actions>
                <x-ui.link-button :href="route('soportes.create')" icon="upload">{{ __('soportes::bandeja.cargar') }}</x-ui.link-button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.tabs :label="__('soportes::bandeja.pestanas.etiqueta')" :items="$tabs" :current="$filter->status->value" />

    <form method="GET" action="{{ route('soportes.index') }}" role="search" aria-label="{{ __('soportes::bandeja.filtros.etiqueta') }}" class="my-6 grid gap-4 rounded-card border border-line bg-surface p-4 md:grid-cols-2 md:p-5 lg:grid-cols-4">
        @if ($filter->status !== ReceiptStatus::Pending)
            <input type="hidden" name="estado" value="{{ $filter->status->value }}">
        @endif

        <x-ui.input name="buscar" type="search" :label="__('soportes::bandeja.filtros.buscar')" :value="$filter->search" maxlength="100" />
        <x-ui.input name="desde" type="date" :label="__('soportes::bandeja.filtros.desde')" :value="$filter->from?->toDateString()" />
        <x-ui.input name="hasta" type="date" :label="__('soportes::bandeja.filtros.hasta')" :value="$filter->to?->toDateString()" />

        <div class="flex flex-wrap items-end gap-2">
            <x-ui.button type="submit" icon="search">{{ __('soportes::bandeja.filtros.aplicar') }}</x-ui.button>
            @if ($filter->isFiltered())
                <x-ui.link-button :href="$clearUrl" variant="ghost">{{ __('soportes::bandeja.filtros.limpiar') }}</x-ui.link-button>
            @endif
        </div>
    </form>

    @if ($receipts->isEmpty())
        @if ($filter->isFiltered())
            <x-ui.empty icon="search" :title="__('soportes::bandeja.vacio.con_filtros')" :description="__('soportes::bandeja.vacio.con_filtros_detalle')">
                <x-ui.link-button :href="$clearUrl" variant="secondary">{{ __('soportes::bandeja.filtros.limpiar') }}</x-ui.link-button>
            </x-ui.empty>
        @else
            <x-ui.empty icon="inbox" :title="$reviews ? __('soportes::bandeja.vacio.equipo') : __('soportes::bandeja.vacio.asesor')" :description="__('soportes::bandeja.vacio.detalle')" />
        @endif
    @else
        <x-ui.table :caption="__('soportes::bandeja.tabla')" :caption-hidden="true" :columns="$columns">
            @foreach ($receipts as $receipt)
                <tr data-receipt="{{ $receipt->id }}">
                    <td class="min-w-44">
                        <span class="block font-semibold">{{ $receipt->client_name }}</span>
                        <span class="text-sm text-fg-muted">{{ $receipt->client_code }}</span>
                    </td>
                    @if ($isGroup)
                        <td><x-ui.chip :dot="false">{{ $receipt->company->value }}</x-ui.chip></td>
                    @endif
                    <td data-align="end"><x-ui.money :value="$receipt->amount" /></td>
                    <td><x-ui.date :value="$receipt->paid_at" /></td>
                    <td data-align="end">{{ $receipt->invoices_count }}</td>
                    <td>{{ $receipt->uploader->name }}</td>
                    <td><x-ui.date :value="$receipt->created_at" :time="true" /></td>
                    <td><x-ui.chip :tone="$receipt->status->tone()">{{ $receipt->status->label() }}</x-ui.chip></td>
                    @if ($rejectedTab)
                        <td class="min-w-44">{{ $receipt->rejection_reason }}</td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$receipts" />
    @endif
</x-layouts.app>
