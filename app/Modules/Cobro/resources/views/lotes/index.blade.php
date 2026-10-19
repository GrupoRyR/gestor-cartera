@use('App\Modules\Cobro\Domain\Runs\RunKind')
@use('App\Modules\Cobro\Domain\Runs\RunStatus')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /cobro/lotes (cobro.lotes.index): the runs of the user's companies,
    newest first. $runs: paginator of Run with "<status>_count" for each
    reported ItemStatus; $kind: the ?RunKind filter (?tipo=); $isGroup:
    shows each run's company.
--}}
@php
    $tabs = [
        ['key' => 'todos', 'label' => __('cobro::lotes.filtros.todos'), 'href' => route('cobro.lotes.index')],
        ...array_map(fn (RunKind $option) => [
            'key' => $option->value,
            'label' => $option->label(),
            'href' => route('cobro.lotes.index', ['tipo' => $option->value]),
        ], RunKind::cases()),
    ];
    $columns = [
        ['key' => 'date', 'label' => __('cobro::lotes.columnas.fecha')],
        ['key' => 'kind', 'label' => __('cobro::lotes.columnas.tipo')],
        ...($isGroup ? [['key' => 'company', 'label' => __('cobro::lotes.columnas.empresa')]] : []),
        ['key' => 'status', 'label' => __('cobro::lotes.columnas.estado')],
        ['key' => 'sent', 'label' => __('cobro::lotes.columnas.enviados'), 'align' => 'end'],
        ['key' => 'bounced', 'label' => __('cobro::lotes.columnas.rebotados'), 'align' => 'end'],
        ['key' => 'no_email', 'label' => __('cobro::lotes.columnas.sin_correo'), 'align' => 'end'],
        ['key' => 'excluded', 'label' => __('cobro::lotes.columnas.excluidos'), 'align' => 'end'],
        ['key' => 'failed', 'label' => __('cobro::lotes.columnas.fallidos'), 'align' => 'end'],
        ['key' => 'actions', 'label' => __('cobro::lotes.columnas.acciones'), 'align' => 'end'],
    ];
    $number = fn (int $value): string => number_format($value, 0, ',', '.');
@endphp

<x-layouts.app :title="__('cobro::lotes.titulo')">
    <x-ui.page-header :title="__('cobro::lotes.titulo')" :description="__('cobro::lotes.descripcion')" />

    <div class="flex flex-col gap-6">
        <x-ui.tabs :label="__('cobro::lotes.filtros.etiqueta')" :items="$tabs" :current="$kind?->value ?? 'todos'" />

        @if ($runs->isEmpty())
            <x-ui.empty icon="history" :title="__('cobro::lotes.vacio.titulo')" :description="__('cobro::lotes.vacio.detalle')" />
        @else
            <div>
                <x-ui.table :caption="__('cobro::lotes.tabla')" :caption-hidden="true" :columns="$columns">
                    @foreach ($runs as $run)
                        <tr data-run="{{ $run->id }}">
                            <td><x-ui.date :value="$run->scheduled_for" :time="true" /></td>
                            <td>{{ $run->kind->label() }}</td>
                            @if ($isGroup)
                                <td><x-ui.chip :dot="false">{{ $run->company->value }}</x-ui.chip></td>
                            @endif
                            <td><x-ui.chip :tone="$run->status === RunStatus::Completed ? 'success' : 'info'">{{ $run->status->label() }}</x-ui.chip></td>
                            <td data-align="end">{{ $number($run->enviado_count) }}</td>
                            <td data-align="end">{{ $number($run->rebotado_count) }}</td>
                            <td data-align="end">{{ $number($run->sin_correo_count) }}</td>
                            <td data-align="end">{{ $number($run->excluido_count) }}</td>
                            <td data-align="end">{{ $number($run->fallido_count + $run->omitido_count) }}</td>
                            <td data-align="end">
                                <x-ui.link-button :href="route('cobro.lotes.show', $run)" variant="ghost" icon="eye">{{ __('cobro::lotes.ver') }}<span data-visually-hidden> {{ __('cobro::lotes.ver_de', ['fecha' => DateFormat::date($run->scheduled_for)]) }}</span></x-ui.link-button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$runs" />
            </div>
        @endif
    </div>
</x-layouts.app>
