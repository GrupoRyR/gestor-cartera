@use('App\Modules\Cartera\Application\Commitments\CommitmentTab')
@use('App\Support\Formatting\Money')

{{--
    GET /cartera/compromisos (cartera.compromisos.index): the commitments
    inbox. $filter: Application\Commitments\CommitmentInboxFilter;
    $commitments: paginator of Commitment within the user's scope;
    $advisorNames: "COMPANY:code" => name; $advisorOptions: code => name for
    the filter; $isGroup: name the company of each commitment.

    The analyst and the manager change the status of any commitment, in
    both companies (cartera.commitments.status): open rows get "Marcar
    cumplido" and "Registrar abono", pending ones also "Marcar
    incumplido". A partially paid one shows what was paid and what is
    left. Editing and cancelling happen in the client file, for SDC only.
--}}
@php
    $canChangeStatus = auth()->user()?->can('cartera.commitments.status') ?? false;
    $total = array_sum(array_map(fn ($commitment): float => $commitment->amount, $commitments->items()));

    $columns = [
        ['key' => 'client', 'label' => __('cartera::compromisos.bandeja.columnas.cliente')],
        ...($isGroup ? [['key' => 'company', 'label' => __('cartera::compromisos.bandeja.columnas.empresa')]] : []),
        ['key' => 'advisor', 'label' => __('cartera::compromisos.bandeja.columnas.asesor')],
        ['key' => 'due_date', 'label' => __('cartera::compromisos.bandeja.columnas.vence'), 'sortable' => true],
        ['key' => 'amount', 'label' => __('cartera::compromisos.bandeja.columnas.valor'), 'sortable' => true, 'align' => 'end'],
        ['key' => 'status', 'label' => __('cartera::compromisos.bandeja.columnas.estado')],
        ['key' => 'created_by', 'label' => __('cartera::compromisos.bandeja.columnas.registrado_por')],
        ['key' => 'created_at', 'label' => __('cartera::compromisos.bandeja.columnas.registrado'), 'sortable' => true],
        ['key' => 'notes', 'label' => __('cartera::compromisos.bandeja.columnas.notas')],
        ...($canChangeStatus ? [['key' => 'actions', 'label' => __('cartera::compromisos.acciones'), 'align' => 'end']] : []),
    ];

    $amountPosition = array_search('amount', array_column($columns, 'key'), true);

    $tabs = array_map(fn (CommitmentTab $tab): array => [
        'key' => $tab->value,
        'label' => __('cartera::compromisos.bandeja.pestanas.'.$tab->value),
        'href' => route('cartera.compromisos.index', $filter->query($tab)),
    ], CommitmentTab::cases());
@endphp

<x-layouts.app :title="__('cartera::compromisos.bandeja.titulo')">
    <x-ui.page-header :title="__('cartera::compromisos.bandeja.titulo')" :description="__('cartera::compromisos.bandeja.descripcion')" />

    <x-ui.tabs :label="__('cartera::compromisos.bandeja.pestanas.etiqueta')" :items="$tabs" :current="$filter->tab->value" />

    <form method="GET" action="{{ route('cartera.compromisos.index') }}" role="search" aria-label="{{ __('cartera::compromisos.bandeja.filtros.etiqueta') }}" class="my-6 grid gap-4 rounded-card border border-line bg-surface p-4 md:grid-cols-2 md:p-5 lg:grid-cols-4">
        @foreach (['estado' => $filter->tab === CommitmentTab::Pending ? null : $filter->tab->value, 'sort' => $filter->sort === 'due_date' ? null : $filter->sort, 'direction' => $filter->direction === 'asc' ? null : $filter->direction] as $name => $value)
            @if ($value !== null)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach

        <x-ui.select
            name="asesor"
            :label="__('cartera::compromisos.bandeja.filtros.asesor')"
            :options="$advisorOptions"
            :value="$filter->advisorCode"
            :placeholder="__('cartera::compromisos.bandeja.filtros.todos_asesores')"
        />
        <x-ui.input name="desde" type="date" :label="__('cartera::compromisos.bandeja.filtros.desde')" :value="$filter->from?->toDateString()" />
        <x-ui.input name="hasta" type="date" :label="__('cartera::compromisos.bandeja.filtros.hasta')" :value="$filter->to?->toDateString()" />

        <div class="flex flex-wrap items-end gap-2">
            <x-ui.button type="submit" icon="search">{{ __('cartera::compromisos.bandeja.filtros.aplicar') }}</x-ui.button>
            @if ($filter->isFiltered())
                <x-ui.link-button :href="route('cartera.compromisos.index', $filter->tab === CommitmentTab::Pending ? [] : ['estado' => $filter->tab->value])" variant="ghost">{{ __('cartera::compromisos.bandeja.filtros.limpiar') }}</x-ui.link-button>
            @endif
        </div>
    </form>

    <p class="mb-4 text-sm text-fg-muted">{{ __($canChangeStatus ? 'cartera::compromisos.bandeja.solo_estado_sgx' : 'cartera::compromisos.bandeja.solo_lectura_sgx') }}</p>

    @if ($commitments->isEmpty())
        @if ($filter->isFiltered())
            <x-ui.empty icon="search" :title="__('cartera::compromisos.bandeja.vacio.con_filtros.titulo')" :description="__('cartera::compromisos.bandeja.vacio.con_filtros.detalle')">
                <x-ui.link-button :href="route('cartera.compromisos.index', $filter->tab === CommitmentTab::Pending ? [] : ['estado' => $filter->tab->value])" variant="secondary">{{ __('cartera::compromisos.bandeja.vacio.con_filtros.accion') }}</x-ui.link-button>
            </x-ui.empty>
        @else
            <x-ui.empty icon="calendar-check" :title="__('cartera::compromisos.bandeja.vacio.sin_filtros.titulo')" :description="__('cartera::compromisos.bandeja.vacio.sin_filtros.detalle')" />
        @endif
    @else
        <x-ui.table
            :caption="__('cartera::compromisos.bandeja.tabla')"
            :caption-hidden="true"
            :columns="$columns"
            :sort-by="$filter->sort"
            :sort-direction="$filter->direction"
        >
            @foreach ($commitments as $commitment)
                <tr>
                    <td class="min-w-44"><x-cartera::client-link :id="$commitment->clientId" :name="$commitment->clientName" /></td>
                    @if ($isGroup)
                        <td><x-ui.chip :dot="false">{{ $commitment->company->value }}</x-ui.chip></td>
                    @endif
                    <td>{{ $advisorNames[$commitment->company->value.':'.$commitment->advisorCode] ?? __('cartera::compromisos.bandeja.sin_asesor') }}</td>
                    <td><x-ui.date :value="$commitment->dueDate" /></td>
                    <td data-align="end">
                        <x-ui.money :value="$commitment->amount" />
                        @if ($commitment->remaining() !== null)
                            <div class="text-sm text-fg-muted">{{ __('cartera::compromisos.pagado', ['pagado' => Money::format($commitment->paidAmount), 'saldo' => Money::format($commitment->remaining())]) }}</div>
                        @endif
                    </td>
                    <td><x-cartera::commitment-status :status="$commitment->status" /></td>
                    <td>{{ $commitment->createdBy->name }}</td>
                    <td><x-ui.date :value="$commitment->createdAt" /></td>
                    <td>{{ $commitment->notes ?? '—' }}</td>
                    @if ($canChangeStatus)
                        <td data-align="end">
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-cartera::commitment-status-actions :commitment="$commitment" :client-name="$commitment->clientName" />
                            </div>
                        </td>
                    @endif
                </tr>
            @endforeach

            <x-slot:footer>
                <tr>
                    <th scope="row" colspan="{{ $amountPosition }}">{{ __('cartera::compromisos.bandeja.total') }}</th>
                    <td data-align="end"><x-ui.money :value="$total" /></td>
                    <td colspan="{{ count($columns) - $amountPosition - 1 }}"></td>
                </tr>
            </x-slot:footer>
        </x-ui.table>

        <x-ui.pagination :paginator="$commitments" />
    @endif
</x-layouts.app>
