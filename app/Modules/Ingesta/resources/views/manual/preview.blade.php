@use('App\Support\Formatting\DateFormat')

{{--
    Answer of POST /ingesta/carga-manual (ingesta.manual.preview): what the
    upload would load, before anything is saved. With validation errors the
    confirm button is disabled and the errors are listed (at most 50).
    $preview: ManualPreview; $token: the key of the stored upload.
--}}
@php
    $batch = $preview->batch;
    $totals = $preview->totals();
    $withoutAdvisor = $preview->withoutAdvisorCount();
    $sample = $preview->sample();

    $errorColumns = [
        ['key' => 'field', 'label' => __('ingesta::manual.vista_previa.errores_columnas.campo')],
        ['key' => 'messages', 'label' => __('ingesta::manual.vista_previa.errores_columnas.mensajes')],
    ];

    $sampleColumns = [
        ['key' => 'client', 'label' => __('ingesta::manual.vista_previa.columnas.cliente')],
        ['key' => 'nit', 'label' => __('ingesta::manual.vista_previa.columnas.nit')],
        ['key' => 'document', 'label' => __('ingesta::manual.vista_previa.columnas.documento')],
        ['key' => 'due', 'label' => __('ingesta::manual.vista_previa.columnas.vencimiento')],
        ['key' => 'days', 'label' => __('ingesta::manual.vista_previa.columnas.dias'), 'align' => 'end'],
        ['key' => 'balance', 'label' => __('ingesta::manual.vista_previa.columnas.saldo'), 'align' => 'end'],
        ['key' => 'advisor', 'label' => __('ingesta::manual.vista_previa.columnas.asesor')],
    ];

    $isDate = fn (mixed $value): bool => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    $isAmount = fn (mixed $value): bool => is_string($value) && is_numeric($value);
@endphp

<x-layouts.app :title="__('ingesta::manual.vista_previa.titulo')">
    <x-ui.page-header :context="__('ingesta::manual.titulo')" :title="__('ingesta::manual.vista_previa.titulo')" :description="__('ingesta::manual.vista_previa.descripcion')">
        <x-slot:actions>
            <form method="POST" action="{{ route('ingesta.manual.discard', $token) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="close">{{ __('ingesta::manual.vista_previa.descartar') }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('ingesta.manual.confirm', $token) }}">
                @csrf
                <x-ui.button type="submit" icon="check" :disabled="! $preview->isValid()">{{ __('ingesta::manual.vista_previa.confirmar') }}</x-ui.button>
            </form>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @unless ($preview->isValid())
            <x-ui.alert tone="danger" :title="__('ingesta::manual.vista_previa.errores_titulo')">{{ __('ingesta::manual.vista_previa.errores') }}</x-ui.alert>
        @endunless

        @if ($withoutAdvisor > 0)
            <x-ui.alert tone="warning" :title="__('ingesta::manual.vista_previa.sin_asesor_titulo')">{{ trans_choice('ingesta::manual.vista_previa.sin_asesor', $withoutAdvisor, ['count' => number_format($withoutAdvisor, 0, ',', '.')]) }}</x-ui.alert>
        @endif

        @if ($preview->ignoredHeaders !== [])
            <x-ui.alert tone="info" :title="__('ingesta::manual.vista_previa.ignoradas_titulo')">{{ __('ingesta::manual.vista_previa.ignoradas', ['columnas' => '«'.implode('», «', $preview->ignoredHeaders).'»']) }}</x-ui.alert>
        @endif

        <x-ui.card :title="__('ingesta::manual.vista_previa.resumen')">
            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-3">
                <x-ui.stat :label="__('ingesta::manual.vista_previa.empresa')" :value="$batch->company->label()" />
                <x-ui.stat :label="__('ingesta::manual.vista_previa.corte')" :value="DateFormat::date($batch->cutoffDate)" />
                <x-ui.stat :label="__('ingesta::manual.vista_previa.filas_stat')" :value="number_format($preview->rowCount(), 0, ',', '.')" />
                <x-ui.stat :label="__('ingesta::manual.vista_previa.clientes')" :value="number_format($preview->clientCount(), 0, ',', '.')" />
                <x-ui.stat :label="__('ingesta::manual.vista_previa.total')">
                    <x-ui.money :value="$totals['total']" />
                </x-ui.stat>
                <x-ui.stat :label="__('ingesta::manual.vista_previa.vencido')">
                    <x-ui.money :value="$totals['overdue']" />
                </x-ui.stat>
            </div>
        </x-ui.card>

        @unless ($preview->isValid())
            <x-ui.table :caption="__('ingesta::manual.vista_previa.errores_tabla')" :columns="$errorColumns">
                @foreach ($preview->errorList() as $error)
                    <tr data-error>
                        <td>{{ $error['field'] }}</td>
                        <td>
                            @foreach ($error['messages'] as $message)
                                <p>{{ $message }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endunless

        <x-ui.table :caption="__('ingesta::manual.vista_previa.muestra', ['count' => count($sample)])" :columns="$sampleColumns">
            @foreach ($sample as $row)
                <tr data-row>
                    <td>{{ $row['nombre_sn'] ?? $row['codigo_sn'] }}</td>
                    <td>{{ $row['nit'] }}</td>
                    <td>{{ $row['documento'] }}</td>
                    <td>
                        @if ($isDate($row['fecha_vencimiento']))
                            <x-ui.date :value="$row['fecha_vencimiento']" />
                        @else
                            {{ $row['fecha_vencimiento'] ?? '—' }}
                        @endif
                    </td>
                    <td data-align="end">{{ $row['dias'] ?? '—' }}</td>
                    <td data-align="end">
                        @if ($isAmount($row['saldo']))
                            <x-ui.money :value="$row['saldo']" />
                        @else
                            {{ $row['saldo'] ?? '—' }}
                        @endif
                    </td>
                    <td>
                        {{ $row['asesor'] ?? '—' }}
                        @if ($row['slp_code'] === null)
                            <x-ui.chip tone="warning">{{ __('ingesta::manual.vista_previa.sin_codigo') }}</x-ui.chip>
                        @else
                            <x-ui.chip :dot="false">{{ $row['slp_code'] }}</x-ui.chip>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </div>
</x-layouts.app>
