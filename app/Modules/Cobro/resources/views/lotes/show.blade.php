@use('App\Modules\Cobro\Domain\Runs\ItemStatus')
@use('App\Modules\Cobro\Domain\Runs\RunStatus')
@use('App\Modules\Cobro\Http\Controllers\RunReportController')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /cobro/lotes/{run} (cobro.lotes.show): the delivery report of one
    run, client by client. $run: Run with its trigger; $items: paginator
    of RunItem filtered by $filter (?ItemStatus, ?estado=); $counts:
    ItemStatus value => clients; $total: clients of the run.
--}}
@php
    $title = __('cobro::lotes.detalle.titulo', ['tipo' => $run->kind->label(), 'fecha' => DateFormat::date($run->scheduled_for)]);
    $shown = array_values(array_filter(RunReportController::REPORTED, fn (ItemStatus $status) => ($counts[$status->value] ?? 0) > 0 || in_array($status, [ItemStatus::Sent, ItemStatus::Bounced, ItemStatus::NoEmail], true)));
    $tabs = [
        ['key' => 'todos', 'label' => __('cobro::lotes.detalle.todos'), 'href' => route('cobro.lotes.show', $run), 'count' => $total],
        ...array_map(fn (ItemStatus $status) => [
            'key' => $status->value,
            'label' => $status->label(),
            'href' => route('cobro.lotes.show', ['run' => $run, 'estado' => $status->value]),
            'count' => $counts[$status->value] ?? 0,
        ], $shown),
    ];
    $columns = [
        ['key' => 'client', 'label' => __('cobro::lotes.detalle.columnas.cliente')],
        ['key' => 'emails', 'label' => __('cobro::lotes.detalle.columnas.correos')],
        ['key' => 'amount', 'label' => __('cobro::lotes.detalle.columnas.valor'), 'align' => 'end'],
        ['key' => 'status', 'label' => __('cobro::lotes.detalle.columnas.estado')],
        ['key' => 'detail', 'label' => __('cobro::lotes.detalle.columnas.detalle')],
        ['key' => 'time', 'label' => __('cobro::lotes.detalle.columnas.hora')],
    ];
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('cobro::lotes.detalle.contexto')">
        <x-slot:actions>
            <x-ui.link-button :href="route('cobro.lotes.index')" variant="ghost" icon="chevron-left">{{ __('cobro::lotes.detalle.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if ($run->status === RunStatus::Sending)
            <x-ui.alert tone="info">{{ __('cobro::lotes.detalle.en_curso') }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat :label="__('cobro::lotes.detalle.empresa')" :value="$run->company->label()" :hint="$run->trigger === null ? __('cobro::lotes.detalle.programado') : __('cobro::lotes.detalle.por', ['nombre' => $run->trigger->name])" />
            <x-ui.stat :label="__('cobro::lotes.detalle.corte')" :value="$run->cutoff_date === null ? null : DateFormat::date($run->cutoff_date)" />
            <x-ui.stat :label="__('cobro::lotes.detalle.iniciado')" :value="DateFormat::dateTime($run->started_at)" />
            <x-ui.stat :label="__('cobro::lotes.detalle.terminado')" :value="$run->finished_at === null ? null : DateFormat::dateTime($run->finished_at)" />
        </div>

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat :label="ItemStatus::Sent->label()" :value="number_format($counts[ItemStatus::Sent->value] ?? 0, 0, ',', '.')" tone="success" :href="route('cobro.lotes.show', ['run' => $run, 'estado' => ItemStatus::Sent->value])" />
            <x-ui.stat :label="ItemStatus::Bounced->label()" :value="number_format($counts[ItemStatus::Bounced->value] ?? 0, 0, ',', '.')" :tone="($counts[ItemStatus::Bounced->value] ?? 0) > 0 ? 'danger' : 'neutral'" :href="route('cobro.lotes.show', ['run' => $run, 'estado' => ItemStatus::Bounced->value])" />
            <x-ui.stat :label="ItemStatus::NoEmail->label()" :value="number_format($counts[ItemStatus::NoEmail->value] ?? 0, 0, ',', '.')" :tone="($counts[ItemStatus::NoEmail->value] ?? 0) > 0 ? 'warning' : 'neutral'" :href="route('cobro.lotes.show', ['run' => $run, 'estado' => ItemStatus::NoEmail->value])" />
            <x-ui.stat :label="ItemStatus::Excluded->label()" :value="number_format($counts[ItemStatus::Excluded->value] ?? 0, 0, ',', '.')" :href="route('cobro.lotes.show', ['run' => $run, 'estado' => ItemStatus::Excluded->value])" />
        </div>

        <x-ui.tabs :label="__('cobro::lotes.detalle.filtros')" :items="$tabs" :current="$filter?->value ?? 'todos'" />

        <div>
            <x-ui.table :caption="__('cobro::lotes.detalle.tabla')" :caption-hidden="true" :columns="$columns" :empty-message="__('cobro::lotes.detalle.vacio')">
                @foreach ($items as $item)
                    <tr data-item="{{ $item->id }}" data-status="{{ $item->status->value }}">
                        <td class="min-w-44">
                            <span class="font-semibold">{{ $item->client_name }}</span>
                            <span class="block text-sm">{{ $item->tax_id }}</span>
                        </td>
                        <td class="[overflow-wrap:anywhere]">{{ $item->recipients === [] ? '—' : implode(', ', $item->recipients) }}</td>
                        <td data-align="end"><x-ui.money :value="$item->amount" /></td>
                        <td><x-ui.chip :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.chip></td>
                        <td class="[overflow-wrap:anywhere]">
                            @if ($item->status === ItemStatus::Bounced)
                                {{ $item->bounced_recipient === null
                                    ? __('cobro::lotes.detalle.rebote_sin_correo', ['motivo' => $item->bounce_reason ?? '—'])
                                    : __('cobro::lotes.detalle.rebote', ['correo' => $item->bounced_recipient, 'motivo' => $item->bounce_reason ?? '—']) }}
                            @elseif ($item->status === ItemStatus::Failed)
                                {{ $item->error }}
                            @elseif ($item->status === ItemStatus::Excluded)
                                {{ $item->exclusion_reason }}
                            @else
                                —
                            @endif
                        </td>
                        <td><x-ui.date :value="$item->sent_at" :time="true" /></td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-ui.pagination :paginator="$items" />
        </div>
    </div>
</x-layouts.app>
