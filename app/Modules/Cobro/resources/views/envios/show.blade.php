@use('App\Modules\Cobro\Domain\Runs\ItemStatus')
@use('App\Support\Formatting\DateFormat')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /cobro/semanal (cobro.semanal.show), and its twin for each
    RunKind: the preview of the next run. $definition: RunDefinition;
    $companies: list<Company> of the definition the user may choose;
    $company: the selected ?Company (null when the user has none).
    With a company: $planned: list<PlannedItem>; $items: paginator of the
    PlannedItem filtered by $filter (?ItemStatus, ?estado=); $counts:
    ItemStatus value => clients; $amount: total to collect from pending
    clients; $nextRunAt: next scheduled moment; $runs: latest Run rows.
--}}
@php
    $kind = $definition->kind();
    $title = $kind->label();
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :description="__('cobro::envios.vista.descripcion.'.$kind->value)" />

    @if ($company === null)
        <x-ui.empty icon="send" :title="__('cobro::envios.vista.sin_empresas.titulo')" :description="__('cobro::envios.vista.sin_empresas.detalle')" />
    @else
        @php
            $companyTabs = array_map(fn ($option) => [
                'key' => $option->code->value,
                'label' => $option->name,
                'href' => route($kind->route('show'), ['empresa' => $option->code->value]),
            ], $companies);
            $statusTabs = [
                ['key' => 'todos', 'label' => __('cobro::envios.vista.todos'), 'href' => route($kind->route('show'), ['empresa' => $company->code->value]), 'count' => count($planned)],
                ...array_map(fn (ItemStatus $status) => [
                    'key' => $status->value,
                    'label' => $status->label(),
                    'href' => route($kind->route('show'), ['empresa' => $company->code->value, 'estado' => $status->value]),
                    'count' => $counts[$status->value],
                ], [ItemStatus::Pending, ItemStatus::NoEmail, ItemStatus::Excluded]),
            ];
            $columns = [
                ['key' => 'client', 'label' => __('cobro::envios.vista.columnas.cliente')],
                ['key' => 'emails', 'label' => __('cobro::envios.vista.columnas.correos')],
                ['key' => 'invoices', 'label' => __('cobro::envios.vista.columnas.facturas'), 'align' => 'end'],
                ['key' => 'amount', 'label' => __('cobro::envios.vista.columnas.valor'), 'align' => 'end'],
                ['key' => 'status', 'label' => __('cobro::envios.vista.columnas.estado')],
                ['key' => 'actions', 'label' => __('cobro::envios.vista.columnas.acciones'), 'align' => 'end'],
            ];
            $testable = array_values(array_filter($planned, fn ($item) => $item->status !== ItemStatus::Excluded));
        @endphp

        <div class="flex flex-col gap-6">
            @if (count($companyTabs) > 1)
                <x-ui.tabs :label="__('cobro::envios.vista.empresas')" :items="$companyTabs" :current="$company->code->value" />
            @endif

            @unless ($definition->sendsOnSchedule())
                <x-ui.alert tone="warning" :title="__('cobro::envios.vista.borrador.titulo')">
                    {{ __('cobro::envios.vista.borrador.detalle') }}
                </x-ui.alert>
            @endunless

            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
                <x-ui.stat
                    :label="__('cobro::envios.vista.proximo')"
                    :value="$definition->sendsOnSchedule()
                        ? __('cobro::envios.vista.proximo_detalle', ['fecha' => DateFormat::date($nextRunAt), 'hora' => $nextRunAt->format('H:i')])
                        : __('cobro::envios.vista.pausado')"
                />
                <x-ui.stat :label="__('cobro::envios.vista.a_enviar')" :value="number_format($counts[ItemStatus::Pending->value], 0, ',', '.')" tone="success" />
                <x-ui.stat :label="__('cobro::envios.vista.sin_correo')" :value="number_format($counts[ItemStatus::NoEmail->value], 0, ',', '.')" :tone="$counts[ItemStatus::NoEmail->value] > 0 ? 'warning' : 'neutral'" />
                <x-ui.stat :label="__('cobro::envios.vista.valor')">
                    <x-ui.money :value="$amount" />
                </x-ui.stat>
            </div>

            <x-ui.tabs :label="__('cobro::envios.vista.filtros')" :items="$statusTabs" :current="$filter?->value ?? 'todos'" />

            @if ($items->isEmpty())
                <x-ui.empty icon="send" :title="__('cobro::envios.vista.vacio.titulo')" :description="__('cobro::envios.vista.vacio.detalle')" />
            @else
                <div>
                    <x-ui.table :caption="__('cobro::envios.vista.tabla')" :caption-hidden="true" :columns="$columns">
                        @foreach ($items as $item)
                            @php($client = $item->candidate->client)
                            <tr data-client="{{ $client->id }}">
                                <td class="min-w-44">
                                    <span class="font-semibold">{{ $client->name }}</span>
                                    <span class="block text-sm">{{ $client->taxId }}</span>
                                </td>
                                <td class="[overflow-wrap:anywhere]">
                                    {{ $item->candidate->emails === [] ? '—' : implode(', ', $item->candidate->emails) }}
                                    @if ($item->candidate->emails !== [] && $item->candidate->cc !== [])
                                        <span class="mt-1 block text-sm">{{ __('cobro::envios.vista.con_copia', ['correos' => implode(', ', $item->candidate->cc)]) }}</span>
                                    @endif
                                </td>
                                <td data-align="end">{{ number_format($item->candidate->invoiceCount, 0, ',', '.') }}</td>
                                <td data-align="end"><x-ui.money :value="$item->candidate->amount" /></td>
                                <td>
                                    <x-ui.chip :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.chip>
                                    @if ($item->exclusionReason !== null)
                                        <span class="mt-1 block text-sm [overflow-wrap:anywhere]">{{ $item->exclusionReason }}</span>
                                    @endif
                                </td>
                                <td data-align="end">
                                    <x-ui.link-button
                                        :href="route($kind->route('correo'), ['empresa' => $company->code->value, 'cliente' => $client->id])"
                                        variant="ghost"
                                        icon="eye"
                                        :external="true"
                                    >{{ __('cobro::envios.vista.ver_correo') }}<span data-visually-hidden> {{ __('cobro::envios.vista.ver_correo_de', ['cliente' => $client->name]) }}</span></x-ui.link-button>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    <x-ui.pagination :paginator="$items" />
                </div>
            @endif

            @can('cobro.manage')
                @if ($testable !== [])
                    <x-ui.card :title="__('cobro::envios.prueba.titulo')" :description="__('cobro::envios.prueba.descripcion')">
                        <form method="POST" action="{{ route($kind->route('prueba'), ['empresa' => $company->code->value]) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div class="min-w-[14rem] flex-1">
                                <x-ui.select
                                    name="cliente"
                                    :label="__('cobro::envios.prueba.cliente')"
                                    :options="collect($testable)->mapWithKeys(fn ($item) => [$item->candidate->client->id => $item->candidate->client->name])->all()"
                                    :required="true"
                                />
                            </div>
                            <x-ui.button type="submit" icon="send">{{ __('cobro::envios.prueba.enviar') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            @if ($runs->isNotEmpty())
                <x-ui.card :title="__('cobro::envios.vista.ultimos')">
                    <ul class="flex flex-col gap-2">
                        @foreach ($runs as $run)
                            <li class="flex flex-wrap items-center gap-2">
                                <x-ui.date :value="$run->scheduled_for" :time="true" />
                                <x-ui.chip :tone="$run->status->value === 'terminado' ? 'success' : 'info'">{{ $run->status->label() }}</x-ui.chip>
                                @if (Route::has('cobro.lotes.show'))
                                    <x-ui.link-button :href="route('cobro.lotes.show', $run)" variant="ghost" icon="history">{{ __('cobro::envios.vista.ver_informes') }}</x-ui.link-button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    @endif
</x-layouts.app>
