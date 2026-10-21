@use('App\Modules\Comisiones\Http\Support\ParameterValues')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')

{{--
    GET /comisiones/parametros/{tipo}/nueva?clave=… (comisiones.parametros.create):
    the form of a new parameter version (§7.2). $kind: ParameterKind; $key;
    $isNew: a code, team or derived commission without versions yet;
    $basis: the ParameterRecord the values come from, or null; $values: its
    "datos" (the default options when none is stored); $from: the suggested
    first day; $firstOpenDay: the first day after the last closed period, or
    null; $closed: that Period. The kind's fields come from
    parametros/formularios/<tipo>. A refusal of ParameterVersions comes back
    as the error «version».
--}}
@php
    $of = $kind->of($key, $basis);
    $title = $isNew ? __('comisiones::parametros.formulario.nuevo.'.$kind->value) : __('comisiones::parametros.lista.nueva_version_de', ['de' => $of]);
    $back = $isNew
        ? route('comisiones.parametros.index', ['pestana' => $kind->tab()])
        : route('comisiones.parametros.historial', ['tipo' => $kind->value, 'clave' => $key]);
    $refusal = $errors->first('version');
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header
        :title="$title"
        :context="__('comisiones::parametros.titulo')"
        :description="$isNew ? __('comisiones::parametros.formulario.descripcion_nuevo') : __('comisiones::parametros.formulario.descripcion')"
    />

    <div class="flex flex-col gap-6">
        @if ($closed !== null)
            <x-ui.alert tone="info">{{ __('comisiones::parametros.cierre', ['fin' => Labels::day($closed->end()), 'mes' => Labels::month($closed)]) }}</x-ui.alert>
        @endif

        @if ($refusal !== '')
            <x-ui.alert tone="danger" :title="__('comisiones::parametros.formulario.no_guardada')">{{ $refusal }}</x-ui.alert>
        @elseif ($errors->any())
            <x-ui.alert tone="danger">{{ __('comisiones::parametros.formulario.revisa') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('comisiones.parametros.'.$kind->value.'.store') }}" class="flex flex-col gap-6" novalidate>
            @csrf

            <x-ui.card
                :title="__('comisiones::parametros.formulario.valores')"
                :description="$basis === null ? null : __('comisiones::parametros.formulario.partida', ['fecha' => ParameterValues::day($basis->vigente_desde)])"
            >
                <div class="flex flex-col gap-4">
                    @include('comisiones::parametros.formularios.'.$kind->value, ['kind' => $kind, 'key' => $key, 'isNew' => $isNew, 'values' => $values])
                </div>
            </x-ui.card>

            <x-ui.card :title="__('comisiones::parametros.formulario.vigencia')">
                <div class="flex flex-col gap-4">
                    <div class="max-w-xs">
                        <x-ui.input
                            name="vigente_desde"
                            type="date"
                            :label="__('comisiones::parametros.formulario.vigente_desde')"
                            :hint="__('comisiones::parametros.formulario.vigente_desde_ayuda')"
                            :value="$from"
                            :required="true"
                            :min="$firstOpenDay"
                        />
                    </div>
                    <x-ui.textarea
                        name="motivo"
                        :label="__('comisiones::parametros.formulario.motivo')"
                        :hint="__('comisiones::parametros.formulario.motivo_ayuda')"
                        :rows="3"
                        :required="true"
                        maxlength="1000"
                    />
                </div>

                <x-slot:footer>
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-ui.link-button variant="ghost" :href="$back">{{ __('comisiones::parametros.formulario.cancelar') }}</x-ui.link-button>
                        <x-ui.button type="submit" icon="check">{{ __('comisiones::parametros.formulario.guardar') }}</x-ui.button>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        </form>
    </div>
</x-layouts.app>
