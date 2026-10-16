@use('App\Modules\Core\Contracts\Enums\LoadStatus')
@use('App\Modules\Ingesta\Application\ErrorKeys')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /ingesta/cargas/{load} (ingesta.cargas.show): one entry of the load
    history. A rejected load shows its summary and the messages per field;
    an accepted cartera load, its totals.
    $load: IngestaLoad with receivedBy; $fieldErrors: array<string, list<string>>
    (empty unless rejected); $totals: whether to show the cartera totals.
--}}
@php
    $title = __('ingesta::historial.detalle.titulo', ['lote' => $load->dataset->label(), 'fecha' => DateFormat::date($load->cutoff_date)]);

    $errorColumns = [
        ['key' => 'field', 'label' => __('ingesta::historial.detalle.errores_columnas.campo')],
        ['key' => 'messages', 'label' => __('ingesta::historial.detalle.errores_columnas.mensajes')],
    ];

    $facts = [
        'empresa' => $load->company->label(),
        'lote' => $load->dataset->label(),
        'corte' => DateFormat::date($load->cutoff_date),
        'origen' => $load->source->label(),
        'filas' => number_format($load->row_count, 0, ',', '.'),
        'recibida' => DateFormat::dateTime($load->received_at),
        'usuario' => $load->receivedBy?->name ?? __('ingesta::historial.detalle.sin_usuario'),
        'identificador' => $load->batch_id,
    ];
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :context="__('ingesta::historial.titulo')" :title="$title" :description="$load->company->label()">
        <x-slot:actions>
            <x-ui.link-button :href="route('ingesta.index')" variant="secondary" icon="history">{{ __('ingesta::historial.detalle.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if ($load->status === LoadStatus::Rejected)
            <x-ui.alert tone="danger" :title="__('ingesta::historial.detalle.rechazada')">{{ $load->error }}</x-ui.alert>
        @endif

        <x-ui.card :title="__('ingesta::historial.detalle.datos')">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-sm text-fg-muted">{{ __('ingesta::historial.detalle.estado') }}</dt>
                    <dd><x-ui.chip :tone="$load->status === LoadStatus::Accepted ? 'success' : 'danger'">{{ $load->status->label() }}</x-ui.chip></dd>
                </div>
                @foreach ($facts as $key => $value)
                    <div class="min-w-0">
                        <dt class="text-sm text-fg-muted">{{ __('ingesta::historial.detalle.'.$key) }}</dt>
                        <dd class="[overflow-wrap:anywhere]">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        @if ($totals)
            <x-ui.card :title="__('ingesta::historial.detalle.totales')">
                <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4">
                    <x-ui.stat :label="__('ingesta::historial.detalle.total')">
                        <x-ui.money :value="$load->total" />
                    </x-ui.stat>
                    <x-ui.stat :label="__('ingesta::historial.detalle.vencido')" :hint="__('ingesta::historial.detalle.vencido_ayuda')">
                        <x-ui.money :value="$load->overdue" />
                    </x-ui.stat>
                </div>
            </x-ui.card>
        @endif

        @if ($fieldErrors !== [])
            <x-ui.table :caption="__('ingesta::historial.detalle.errores')" :columns="$errorColumns">
                @foreach ($fieldErrors as $key => $messages)
                    <tr>
                        <td>{{ ErrorKeys::describe((string) $key) }}</td>
                        <td>
                            @foreach ($messages as $message)
                                <p>{{ $message }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </div>
</x-layouts.app>
