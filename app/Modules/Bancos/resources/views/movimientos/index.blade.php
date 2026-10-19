@use('App\Modules\Bancos\Domain\Movement\MovementStatus')
@use('App\Modules\Core\Contracts\Enums\Bank')

{{--
    GET /bancos/movimientos/{banco} (bancos.movimientos.banco): the credits of
    one bank, 25 per page, with a tab per bank (each with its pending count),
    filters by date, amount, status and text, and sortable columns.
    $bank: Bank; $filter: MovementFilter; $movements: paginator of BankMovement;
    $totals: list<['currency','total','count','pending']> of the filtered list;
    $pending: bank value => pending credits; $showCompany: whether the user
    sees more than one company.
--}}
@php
    $tabs = collect(Bank::cases())->map(fn (Bank $tab): array => [
        'key' => $tab->value,
        'label' => $tab->label(),
        'href' => route('bancos.movimientos.banco', $tab->value),
        'count' => $pending[$tab->value] ?? 0,
    ])->all();

    $columns = [
        ['key' => 'date', 'label' => __('bancos::movimientos.columnas.fecha'), 'sortable' => true],
        ...($showCompany ? [['key' => 'company', 'label' => __('bancos::movimientos.columnas.empresa')]] : []),
        ['key' => 'description', 'label' => __('bancos::movimientos.columnas.descripcion')],
        ['key' => 'reference', 'label' => __('bancos::movimientos.columnas.referencia')],
        ['key' => 'amount', 'label' => __('bancos::movimientos.columnas.valor'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'status', 'label' => __('bancos::movimientos.columnas.estado'), 'sortable' => true],
        ['key' => 'receipt', 'label' => __('bancos::movimientos.columnas.recibo')],
        ['key' => 'actions', 'label' => __('bancos::movimientos.columnas.acciones'), 'align' => 'end'],
    ];

    $count = array_sum(array_column($totals, 'count'));
    $pendingCount = array_sum(array_column($totals, 'pending'));
@endphp

<x-layouts.app :title="$bank->label().' · '.__('bancos::movimientos.titulo')">
    <x-ui.page-header :title="__('bancos::movimientos.titulo')" :description="__('bancos::movimientos.descripcion')" />

    <div class="flex flex-col gap-6">
        <x-ui.tabs :label="__('bancos::movimientos.tabs')" :items="$tabs" :current="$bank->value" />

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat :label="__('bancos::movimientos.resumen.movimientos')" :value="number_format($count, 0, ',', '.')" />
            @forelse ($totals as $total)
                <x-ui.stat :label="count($totals) > 1 ? __('bancos::movimientos.resumen.valor_en', ['moneda' => $total['currency']]) : __('bancos::movimientos.resumen.valor')">
                    <x-bancos::amount :value="$total['total']" :currency="$total['currency']" />
                </x-ui.stat>
            @empty
                <x-ui.stat :label="__('bancos::movimientos.resumen.valor')">
                    <x-bancos::amount value="0" />
                </x-ui.stat>
            @endforelse
            <x-ui.stat :label="__('bancos::movimientos.resumen.pendientes')" :value="number_format($pendingCount, 0, ',', '.')" :tone="$pendingCount > 0 ? 'warning' : 'neutral'" />
        </div>

        <form method="GET" action="{{ route('bancos.movimientos.banco', $bank->value) }}" aria-label="{{ __('bancos::movimientos.filtros.etiqueta') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[10rem] flex-1">
                <x-ui.input name="desde" type="date" :label="__('bancos::movimientos.filtros.desde')" :value="$filter->from?->format('Y-m-d')" />
            </div>
            <div class="min-w-[10rem] flex-1">
                <x-ui.input name="hasta" type="date" :label="__('bancos::movimientos.filtros.hasta')" :value="$filter->to?->format('Y-m-d')" />
            </div>
            <div class="min-w-[10rem] flex-1">
                <x-ui.input name="valor_min" type="number" min="0" step="any" inputmode="decimal" :label="__('bancos::movimientos.filtros.valor_min')" :value="$filter->minAmount" />
            </div>
            <div class="min-w-[10rem] flex-1">
                <x-ui.input name="valor_max" type="number" min="0" step="any" inputmode="decimal" :label="__('bancos::movimientos.filtros.valor_max')" :value="$filter->maxAmount" />
            </div>
            <div class="min-w-[10rem] flex-1">
                <x-ui.select name="estado" :label="__('bancos::movimientos.filtros.estado')" :options="MovementStatus::options()" :value="$filter->status" :placeholder="__('bancos::movimientos.filtros.todos')" />
            </div>
            <div class="min-w-[12rem] flex-1">
                <x-ui.input name="q" type="search" :label="__('bancos::movimientos.filtros.buscar')" :value="$filter->text" />
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter">{{ __('bancos::movimientos.filtros.aplicar') }}</x-ui.button>
                @if ($filter->isNarrowed())
                    <x-ui.link-button :href="route('bancos.movimientos.banco', $bank->value)" variant="ghost">{{ __('bancos::movimientos.filtros.limpiar') }}</x-ui.link-button>
                @endif
            </div>
        </form>

        @if ($movements->isEmpty())
            <x-ui.empty
                icon="bank"
                :title="$filter->isNarrowed() ? __('bancos::movimientos.vacio_filtrado.titulo') : __('bancos::movimientos.vacio.titulo', ['banco' => $bank->label()])"
                :description="$filter->isNarrowed() ? __('bancos::movimientos.vacio_filtrado.detalle') : __('bancos::movimientos.vacio.detalle')"
            >
                @unless ($filter->isNarrowed())
                    <x-ui.link-button :href="route('bancos.cargas.index')" variant="secondary">{{ __('bancos::movimientos.vacio.ir_a_cargas') }}</x-ui.link-button>
                @endunless
            </x-ui.empty>
        @else
            <div>
                <x-ui.table
                    :caption="__('bancos::movimientos.tabla', ['banco' => $bank->label()])"
                    :caption-hidden="true"
                    :columns="$columns"
                    :sort-by="$filter->sort"
                    :sort-direction="$filter->direction"
                >
                    @foreach ($movements as $movement)
                        <tr>
                            <td><x-ui.date :value="$movement->movement_date" /></td>
                            @if ($showCompany)
                                <td>{{ $movement->company->value }}</td>
                            @endif
                            <td>{{ $movement->description ?? '—' }}</td>
                            <td>{{ $movement->reference ?? '—' }}</td>
                            <td data-align="end"><x-bancos::amount :value="$movement->amount" :currency="$movement->currency" /></td>
                            <td><x-ui.chip :tone="$movement->status->tone()">{{ $movement->status->label() }}</x-ui.chip></td>
                            <td>{{ $movement->receipt_number ?? __('bancos::movimientos.sin_recibo') }}</td>
                            <td data-align="end">
                                <x-ui.link-button :href="route('bancos.movimientos.show', $movement)" variant="ghost" icon="eye">{{ __('bancos::movimientos.abrir') }}<span data-visually-hidden> {{ __('bancos::movimientos.abrir_de', ['valor' => number_format((float) $movement->amount, 2, ',', '.').' '.$movement->currency, 'fecha' => $movement->movement_date->format('d/m/Y')]) }}</span></x-ui.link-button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$movements" />
            </div>
        @endif
    </div>
</x-layouts.app>
