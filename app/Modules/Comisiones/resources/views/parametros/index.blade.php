@use('App\Modules\Comisiones\Http\Controllers\ParametersController')
@use('App\Modules\Comisiones\Http\Support\ParameterKind')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /comisiones/parametros/{pestana?}?fecha=YYYY-MM-DD (comisiones.parametros.index):
    the parameters in force on a day (§7.2), one tab per kind. $kind:
    ParameterKind of the tab; $day: "Y-m-d" and $dayLabel: "d/m/Y"; $entries: list<ParameterEntry>
    of the kind on that day; $defaults: the options' default values (options
    tab); $closed: the last closed Period or null. The tab's content comes
    from parametros/pestanas/<tab>.
--}}
@php
    $title = __('comisiones::parametros.titulo');
    $tab = $kind->tab();
    $dateQuery = $day === ParametersController::today() ? [] : ['fecha' => $day];

    $tabs = [];
    foreach (ParameterKind::cases() as $each) {
        $tabs[] = [
            'key' => $each->tab(),
            'label' => __('comisiones::parametros.pestanas.'.$each->tab()),
            'href' => route('comisiones.parametros.index', ['pestana' => $each->tab(), ...$dateQuery]),
        ];
    }

    $canAdd = Route::has('comisiones.parametros.create');
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('comisiones::parametros.contexto')" :description="__('comisiones::parametros.descripcion')">
        <x-slot:actions>
            <form method="GET" action="{{ route('comisiones.parametros.index', ['pestana' => $tab]) }}" aria-label="{{ __('comisiones::parametros.fecha.etiqueta') }}" class="flex flex-wrap items-end gap-2">
                <x-ui.input name="fecha" type="date" :label="__('comisiones::parametros.fecha.campo')" :value="$day" />
                <x-ui.button type="submit" variant="secondary">{{ __('comisiones::parametros.fecha.ver') }}</x-ui.button>
            </form>
            @if ($canAdd)
                <x-ui.link-button :href="route('comisiones.parametros.create', ['tipo' => $kind->value])" icon="plus">{{ __('comisiones::parametros.'.$tab.'.nuevo') }}</x-ui.link-button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6">
        <x-ui.tabs :label="__('comisiones::parametros.pestanas.etiqueta')" :items="$tabs" :current="$tab" />
    </div>

    <div class="flex flex-col gap-6">
        @if ($closed !== null)
            <x-ui.alert tone="info">{{ __('comisiones::parametros.cierre', ['fin' => Labels::day($closed->end()), 'mes' => Labels::month($closed)]) }}</x-ui.alert>
        @endif

        <p class="text-sm text-fg-soft">{{ __('comisiones::parametros.fecha.viendo', ['fecha' => $dayLabel]) }}</p>

        @include('comisiones::parametros.pestanas.'.$tab, ['kind' => $kind, 'entries' => $entries, 'defaults' => $defaults, 'day' => $day, 'canAdd' => $canAdd])
    </div>
</x-layouts.app>
