@use('App\Modules\Comisiones\Infrastructure\Captures\CaptureKind')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /comisiones/ajustes/{pestana?}?periodo=YYYY-MM (comisiones.ajustes.index):
    the manual captures of a period (§7.4). $period; $kind: CaptureKind of
    the tab; $captures: its captures of the period, newest first; $pending:
    tab => pending count; $locked: the period is closed (PeriodLock);
    $codes: code => label of the codes in force; $canCapture. The tab's
    columns come from ajustes/celdas/<tab> and its form from
    ajustes/formularios/<tab>, shown once its store route exists.
--}}
@php
    $title = __('comisiones::ajustes.titulo');
    $tab = $kind->tab();
    $storeRoute = "comisiones.ajustes.{$tab}.store";
    $editable = $canCapture && ! $locked && $codes !== [] && Route::has($storeRoute);
    $modalId = "nuevo-{$tab}";

    $tabs = [];
    foreach (CaptureKind::cases() as $each) {
        $tabs[] = [
            'key' => $each->tab(),
            'label' => __('comisiones::ajustes.pestanas.'.$each->tab()),
            'href' => route('comisiones.ajustes.index', ['pestana' => $each->tab(), 'periodo' => $period->key()]),
            'count' => $pending[$each->tab()] > 0 ? $pending[$each->tab()] : null,
        ];
    }

    $columns = [];
    $detail = trans('comisiones::ajustes.'.$tab.'.columnas');
    foreach (is_array($detail) ? $detail : [] as $key => $label) {
        $columns[] = ['key' => $key, 'label' => $label, 'align' => in_array($key, ['valor', 'valor_vendido', 'valor_cruzado', 'tasa'], true) ? 'end' : 'start'];
    }
    foreach (['estado', 'registro', 'revision', 'soporte', 'acciones'] as $key) {
        $columns[] = ['key' => $key, 'label' => __('comisiones::ajustes.lista.columnas.'.$key)];
    }
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('comisiones::comun.contexto_periodo', ['mes' => Labels::month($period)])" :description="__('comisiones::ajustes.descripcion')">
        <x-slot:actions>
            <form method="GET" action="{{ route('comisiones.ajustes.index', ['pestana' => $tab]) }}" aria-label="{{ __('comisiones::ajustes.periodo.etiqueta') }}" class="flex flex-wrap items-end gap-2">
                <x-ui.input name="periodo" type="month" :label="__('comisiones::ajustes.periodo.campo')" :value="$period->key()" />
                <x-ui.button type="submit" variant="secondary">{{ __('comisiones::ajustes.periodo.ver') }}</x-ui.button>
            </form>
            @if ($editable)
                <x-ui.button icon="plus" :data-modal-open="$modalId">{{ __('comisiones::ajustes.'.$tab.'.nuevo') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6">
        <x-ui.tabs :label="__('comisiones::ajustes.pestanas.etiqueta')" :items="$tabs" :current="$tab" />
    </div>

    <div class="flex flex-col gap-6">
        @if ($locked)
            <x-ui.alert tone="warning">{{ __('comisiones::comun.periodo_cerrado') }}</x-ui.alert>
        @endif

        @if ($codes === [])
            <x-ui.alert tone="danger">{{ __('comisiones::ajustes.sin_codigos', ['periodo' => $period->key()]) }}</x-ui.alert>
        @endif

        <x-ui.card :title="__('comisiones::ajustes.'.$tab.'.titulo')">
            <x-ui.table :caption="__('comisiones::ajustes.'.$tab.'.caption')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::ajustes.lista.vacio', ['periodo' => $period->key()])">
                @foreach ($captures as $capture)
                    <tr data-capture="{{ $capture->id }}" data-estado="{{ $capture->estado->value }}">
                        @include('comisiones::ajustes.celdas.'.$tab, ['capture' => $capture])
                        @include('comisiones::ajustes.comunes', ['capture' => $capture, 'kind' => $kind, 'period' => $period, 'locked' => $locked])
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>

    @if ($editable)
        <x-ui.modal :id="$modalId" :title="__('comisiones::ajustes.'.$tab.'.nuevo')" :open="$errors->any() && old('formulario') === $tab">
            <form id="form-{{ $modalId }}" method="POST" action="{{ route($storeRoute) }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="formulario" value="{{ $tab }}">
                <input type="hidden" name="periodo" value="{{ $period->key() }}">
                @include('comisiones::ajustes.formularios.'.$tab, ['period' => $period, 'codes' => $codes])
                <x-ui.input
                    name="soporte"
                    type="file"
                    :label="__('comisiones::ajustes.formulario.soporte')"
                    :hint="__('comisiones::ajustes.formulario.soporte_ayuda')"
                    :required="true"
                    accept=".pdf,.jpg,.jpeg,.png,.xlsx,.csv"
                />
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" data-modal-close>{{ __('comisiones::ajustes.formulario.cancelar') }}</x-ui.button>
                <x-ui.button type="submit" :form="'form-'.$modalId">{{ __('comisiones::ajustes.formulario.guardar') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-layouts.app>
