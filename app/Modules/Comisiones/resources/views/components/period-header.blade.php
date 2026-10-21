@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('Illuminate\Support\Facades\Route')

{{--
    The head of every screen of a period: the page header with the month as
    context, and the period tabs (Resumen, Consolidado, Controles, Informes).
    A tab shows only once its route exists and only to a user its permission
    lets in, so nobody meets a 403 from a tab. $version keeps ?version= in the
    tab links when an older version is shown. Slot "actions": the page header
    actions.
--}}
@props(['period', 'title', 'current' => 'resumen', 'version' => null, 'description' => null])

@php
    $query = $version === null ? [] : ['version' => $version];
    $tabs = [];

    foreach ([
        'resumen' => ['comisiones.periodos.show', 'comisiones.view'],
        'consolidado' => ['comisiones.periodos.consolidado', 'comisiones.view'],
        'controles' => ['comisiones.periodos.controles', 'comisiones.view'],
        'informes' => ['comisiones.periodos.informes.index', 'comisiones.reports'],
    ] as $key => [$name, $permission]) {
        if (Route::has($name) && auth()->user()?->can($permission)) {
            $tabs[] = [
                'key' => $key,
                'label' => __('comisiones::comun.pestanas.'.$key),
                'href' => route($name, ['periodo' => $period->key(), ...$query]),
            ];
        }
    }
@endphp

<x-ui.page-header :title="$title" :context="__('comisiones::comun.contexto_periodo', ['mes' => Labels::month($period)])" :description="$description">
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-ui.page-header>

@if (count($tabs) > 1)
    <div class="mb-6">
        <x-ui.tabs :label="__('comisiones::comun.pestanas.etiqueta')" :items="$tabs" :current="$current" />
    </div>
@endif
