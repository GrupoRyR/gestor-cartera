@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{--
    The validity cell of a key on the chosen day. $entry: ParameterEntry.
    In force: «Desde 01/01/2026», plus «Cambia el …» when a later version
    exists; not yet in force: «Empieza el …».
--}}
@if ($entry->current !== null)
    <span class="block whitespace-nowrap">{{ __('comisiones::parametros.valores.desde', ['fecha' => ParameterValues::day($entry->current->vigente_desde)]) }}</span>
    @if ($entry->next !== null)
        <span class="mt-1 block"><x-ui.chip tone="info">{{ __('comisiones::parametros.valores.cambia', ['fecha' => ParameterValues::day($entry->next->vigente_desde)]) }}</x-ui.chip></span>
    @endif
@elseif ($entry->next !== null)
    <x-ui.chip tone="info">{{ __('comisiones::parametros.valores.empieza', ['fecha' => ParameterValues::day($entry->next->vigente_desde)]) }}</x-ui.chip>
@endif
