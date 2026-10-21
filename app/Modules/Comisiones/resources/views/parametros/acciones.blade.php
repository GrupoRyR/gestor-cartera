{{--
    The links of a key: its history and, with the form route, a new version
    prefilled from it. $kind: ParameterKind; $key; $of: «del código 14»…;
    $canAdd: the form route exists.
--}}
<div class="flex flex-wrap gap-2">
    <x-ui.link-button
        variant="ghost"
        icon="history"
        :href="route('comisiones.parametros.historial', ['tipo' => $kind->value, 'clave' => $key])"
        :aria-label="__('comisiones::parametros.lista.historial_de', ['de' => $of])"
    >{{ __('comisiones::parametros.lista.historial') }}</x-ui.link-button>
    @if ($canAdd)
        <x-ui.link-button
            variant="secondary"
            icon="plus"
            :href="route('comisiones.parametros.create', ['tipo' => $kind->value, ...($kind->keyed() ? ['clave' => $key] : [])])"
            :aria-label="__('comisiones::parametros.lista.nueva_version_de', ['de' => $of])"
        >{{ __('comisiones::parametros.lista.nueva_version') }}</x-ui.link-button>
    @endif
</div>
