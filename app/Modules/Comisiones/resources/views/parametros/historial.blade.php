@use('App\Modules\Comisiones\Http\Support\ParameterValues')
@use('App\Modules\Comisiones\Infrastructure\Audit\AuditTrail')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /comisiones/parametros/{tipo}/{clave} (comisiones.parametros.historial):
    every version of a key, newest first (the bitácora of §7.2). $kind:
    ParameterKind; $key; $versions: list<ParameterRecord> with creator;
    $today: "Y-m-d"; $closed: the last closed Period or null. A version
    without author is the seed («Sistema»).
--}}
@php
    $of = $kind->of($key, $versions[0] ?? null);
    $title = __('comisiones::parametros.lista.historial_de', ['de' => $of]);
    $canAdd = Route::has('comisiones.parametros.create');
    $columns = [];
    foreach (['estado', 'desde', 'hasta', 'valores', 'motivo', 'autor', 'registro'] as $field) {
        $columns[] = ['key' => $field, 'label' => __('comisiones::parametros.historial.columnas.'.$field)];
    }
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('comisiones::parametros.titulo')" :description="__('comisiones::parametros.historial.descripcion')">
        <x-slot:actions>
            <x-ui.link-button variant="secondary" icon="chevron-left" :href="route('comisiones.parametros.index', ['pestana' => $kind->tab()])">{{ __('comisiones::parametros.historial.volver') }}</x-ui.link-button>
            @if ($canAdd)
                <x-ui.link-button icon="plus" :href="route('comisiones.parametros.create', ['tipo' => $kind->value, ...($kind->keyed() ? ['clave' => $key] : [])])">{{ __('comisiones::parametros.lista.nueva_version') }}</x-ui.link-button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if ($closed !== null)
            <x-ui.alert tone="info">{{ __('comisiones::parametros.cierre', ['fin' => Labels::day($closed->end()), 'mes' => Labels::month($closed)]) }}</x-ui.alert>
        @endif

        @if ($versions === [] && $kind->value === 'opciones')
            <x-ui.alert tone="info">{{ __('comisiones::parametros.opciones.por_defecto') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <x-ui.table :caption="__('comisiones::parametros.historial.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::parametros.historial.vacio')">
                @foreach ($versions as $version)
                    @php
                        $from = $version->vigente_desde->format('Y-m-d');
                        $until = $version->vigente_hasta?->format('Y-m-d');
                        $state = $from > $today ? 'proxima' : ($until !== null && $until < $today ? 'anterior' : 'vigente');
                    @endphp
                    <tr data-version="{{ $version->id }}">
                        <td data-col="estado">
                            @if ($state === 'vigente')
                                <x-ui.chip tone="success">{{ __('comisiones::parametros.historial.vigente') }}</x-ui.chip>
                            @elseif ($state === 'proxima')
                                <x-ui.chip tone="info">{{ __('comisiones::parametros.historial.proxima') }}</x-ui.chip>
                            @else
                                <span class="text-fg-soft">{{ __('comisiones::parametros.historial.anterior') }}</span>
                            @endif
                        </td>
                        <td data-col="desde" class="whitespace-nowrap">{{ ParameterValues::day($version->vigente_desde) }}</td>
                        <td data-col="hasta" class="whitespace-nowrap">{{ $version->vigente_hasta === null ? __('comisiones::parametros.historial.abierta') : ParameterValues::day($version->vigente_hasta) }}</td>
                        <td data-col="valores" class="min-w-64">
                            <ul class="flex flex-col gap-0.5 text-sm">
                                @foreach (ParameterValues::lines($kind, $version->datos) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </td>
                        <td data-col="motivo" class="min-w-48">{{ ParameterValues::text($version->motivo) }}</td>
                        <td data-col="autor" class="min-w-32">{{ $version->created_by === null ? AuditTrail::SYSTEM : ($version->creator?->name ?? __('comisiones::comun.sin_dato')) }}</td>
                        <td data-col="registro" class="whitespace-nowrap"><x-ui.date :value="$version->created_at" :time="true" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</x-layouts.app>
