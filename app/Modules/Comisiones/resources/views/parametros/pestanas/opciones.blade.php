@use('App\Modules\Comisiones\Http\Support\OptionFields')

{{--
    The «Opciones» tab: the answer in force to each open point 14.2–14.7.
    $kind, $entries (at most one), $defaults (the values when none is
    stored), $canAdd.
--}}
@php
    $entry = $entries[0] ?? null;
    $data = $entry === null ? $defaults : $entry->shown()->datos;
    $columns = [];
    foreach (['punto', 'opcion', 'valor', 'detalle'] as $field) {
        $columns[] = ['key' => $field, 'label' => __('comisiones::parametros.opciones.columnas.'.$field)];
    }
@endphp

@if ($entry === null)
    <x-ui.alert tone="info">{{ __('comisiones::parametros.opciones.por_defecto') }}</x-ui.alert>
@endif

<x-ui.card :title="__('comisiones::parametros.opciones.titulo')" :description="__('comisiones::parametros.opciones.descripcion')">
    <x-slot:actions>
        @include('comisiones::parametros.acciones', ['kind' => $kind, 'key' => $kind->value, 'of' => $kind->of($kind->value), 'canAdd' => $canAdd])
    </x-slot:actions>

    @if ($entry !== null)
        <div class="mb-4">@include('comisiones::parametros.vigencia', ['entry' => $entry])</div>
    @endif

    <x-ui.table :caption="__('comisiones::parametros.opciones.caption')" :caption-hidden="true" :columns="$columns">
        @foreach (array_keys(OptionFields::FIELDS) as $field)
            <tr data-key="{{ $field }}">
                <td data-col="punto" class="whitespace-nowrap">{{ OptionFields::point($field) }}</td>
                <td data-col="opcion" class="min-w-48 font-semibold">{{ OptionFields::label($field) }}</td>
                <td data-col="valor" class="min-w-40">{{ OptionFields::display($field, $data[$field] ?? null) }}</td>
                <td data-col="detalle" class="min-w-64 text-sm text-fg-soft">{{ OptionFields::hint($field) }}</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
