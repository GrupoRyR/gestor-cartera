@use('App\Modules\Comisiones\Infrastructure\Captures\Amount')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /comisiones/presupuesto?periodo=YYYY-MM (comisiones.presupuesto.index):
    the monthly budget (§7.3). $period; $rows: list<BudgetRow>, one per code
    in force; $editors: code => name of the last user who changed it;
    $locked: the period is closed (PeriodLock); $canCapture: the user holds
    comisiones.capture. Each row is its own form (inputs bound with form=),
    named filas[{code}][…] so an error shows under its row.
--}}
@php
    $title = __('comisiones::presupuesto.titulo');
    $editable = $canCapture && ! $locked;
    $missing = array_values(array_filter($rows, fn ($row) => $row->needsBudget()));
    $columns = [
        ['key' => 'codigo', 'label' => __('comisiones::presupuesto.tabla.columnas.codigo')],
        ['key' => 'nombre', 'label' => __('comisiones::presupuesto.tabla.columnas.nombre')],
        ['key' => 'minimo', 'label' => __('comisiones::presupuesto.tabla.columnas.minimo'), 'align' => 'end'],
        ['key' => 'presupuesto', 'label' => __('comisiones::presupuesto.tabla.columnas.presupuesto'), 'align' => $editable ? 'start' : 'end'],
        ['key' => 'canal', 'label' => __('comisiones::presupuesto.tabla.columnas.canal')],
        ['key' => 'editado', 'label' => __('comisiones::presupuesto.tabla.columnas.editado')],
    ];

    if ($editable) {
        $columns[] = ['key' => 'acciones', 'label' => __('comisiones::presupuesto.tabla.columnas.acciones')];
    }
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('comisiones::comun.contexto_periodo', ['mes' => Labels::month($period)])" :description="__('comisiones::presupuesto.descripcion')">
        <x-slot:actions>
            <form method="GET" action="{{ route('comisiones.presupuesto.index') }}" aria-label="{{ __('comisiones::presupuesto.periodo.etiqueta') }}" class="flex flex-wrap items-end gap-2">
                <x-ui.input name="periodo" type="month" :label="__('comisiones::presupuesto.periodo.campo')" :value="$period->key()" />
                <x-ui.button type="submit" variant="secondary">{{ __('comisiones::presupuesto.periodo.ver') }}</x-ui.button>
            </form>
            @if ($canCapture && $rows !== [])
                <x-ui.link-button :href="route('comisiones.presupuesto.template', $period->key())" variant="secondary" icon="download">{{ __('comisiones::presupuesto.carga.plantilla') }}</x-ui.link-button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if ($locked)
            <x-ui.alert tone="warning">{{ __('comisiones::comun.periodo_cerrado') }}</x-ui.alert>
        @endif

        @if ($rows === [])
            <x-ui.alert tone="danger">{{ __('comisiones::presupuesto.sin_parametros', ['periodo' => $period->key()]) }}</x-ui.alert>
        @endif

        @if (session('errores_carga'))
            <x-ui.alert tone="danger" :title="__('comisiones::presupuesto.carga.con_errores')">
                <ul class="list-disc ps-5">
                    @foreach ((array) session('errores_carga') as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($missing !== [])
            <x-ui.alert tone="warning" :title="__('comisiones::presupuesto.faltantes.titulo')">
                <p>{{ __('comisiones::presupuesto.faltantes.detalle') }}</p>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ($missing as $row)
                        <li data-missing="{{ $row->rule->code }}"><span class="font-semibold">{{ $row->rule->code }}</span> · {{ $row->rule->name }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($editable && $rows !== [])
            <x-ui.card :title="__('comisiones::presupuesto.carga.titulo')" :description="__('comisiones::presupuesto.carga.descripcion')">
                <form method="POST" action="{{ route('comisiones.presupuesto.import', $period->key()) }}" enctype="multipart/form-data" class="flex flex-col gap-4 md:flex-row md:items-end">
                    @csrf
                    <div class="min-w-0 flex-1">
                        <x-ui.input
                            name="archivo"
                            type="file"
                            :label="__('comisiones::presupuesto.carga.archivo')"
                            :hint="__('comisiones::presupuesto.carga.archivo_ayuda')"
                            :required="true"
                            accept=".csv,.xlsx"
                        />
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="upload" class="max-sm:w-full max-sm:justify-center">{{ __('comisiones::presupuesto.carga.enviar') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('comisiones::presupuesto.tabla.titulo')">
            <x-ui.table :caption="__('comisiones::presupuesto.tabla.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::presupuesto.tabla.vacio')">
                @foreach ($rows as $row)
                    @php
                        $code = $row->rule->code;
                        $formId = "presupuesto-{$code}";
                    @endphp
                    <tr data-code="{{ $code }}">
                        <td class="font-semibold">{{ $code }}</td>
                        <td class="min-w-40">{{ $row->rule->name }}</td>
                        <td data-align="end">{{ Labels::rate($row->rule->minimumCompliance) }}</td>
                        @if ($editable)
                            <td class="min-w-44">
                                <x-ui.input
                                    name="filas[{{ $code }}][valor]"
                                    :label="__('comisiones::presupuesto.tabla.campo_presupuesto', ['codigo' => $code])"
                                    :label-hidden="true"
                                    :value="Amount::forInput($row->budget?->valor)"
                                    prefix="$"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :form="$formId"
                                />
                            </td>
                            <td class="min-w-40">
                                <x-ui.input
                                    name="filas[{{ $code }}][canal]"
                                    :label="__('comisiones::presupuesto.tabla.campo_canal', ['codigo' => $code])"
                                    :label-hidden="true"
                                    :value="$row->channel()"
                                    maxlength="100"
                                    :form="$formId"
                                />
                            </td>
                        @else
                            <td data-align="end">
                                @if ($row->budget === null)
                                    {{ __('comisiones::presupuesto.tabla.sin_presupuesto') }}
                                @else
                                    <x-ui.money :value="$row->budget->valor" />
                                @endif
                            </td>
                            <td>{{ $row->budget?->canal ?? __('comisiones::comun.sin_dato') }}</td>
                        @endif
                        <td class="text-sm">
                            @if ($row->budget === null)
                                {{ __('comisiones::comun.sin_dato') }}
                            @else
                                {{ __('comisiones::presupuesto.tabla.editado_por', ['fecha' => DateFormat::dateTime($row->budget->updated_at), 'nombre' => $editors[$code] ?? __('comisiones::comun.sin_dato')]) }}
                            @endif
                        </td>
                        @if ($editable)
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <form id="{{ $formId }}" method="POST" action="{{ route('comisiones.presupuesto.update', ['periodo' => $period->key(), 'codigo' => $code]) }}">
                                        @csrf
                                        @method('PUT')
                                        <x-ui.button type="submit" variant="secondary" icon="check" :aria-label="__('comisiones::presupuesto.tabla.guardar_codigo', ['codigo' => $code])">{{ __('comisiones::presupuesto.tabla.guardar') }}</x-ui.button>
                                    </form>
                                    @if ($row->budget !== null)
                                        <form method="POST" action="{{ route('comisiones.presupuesto.destroy', ['periodo' => $period->key(), 'codigo' => $code]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="ghost" icon="trash" :icon-only="true">{{ __('comisiones::presupuesto.tabla.eliminar_codigo', ['codigo' => $code]) }}</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</x-layouts.app>
