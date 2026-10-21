@use('App\Modules\Comisiones\Http\Support\OptionFields')
@use('App\Modules\Comisiones\Http\Support\ParameterValues')

{{--
    The settlement options (§14.2–§14.7), one field each with the open point
    it answers and what each choice means. $values: the stored "datos" to
    start from (the defaults when none is stored).
--}}
@foreach (array_keys(OptionFields::FIELDS) as $field)
    @php($label = __('comisiones::parametros.opciones.columnas.punto').' '.OptionFields::point($field).' · '.OptionFields::label($field))
    @switch (OptionFields::kind($field))
        @case (OptionFields::BOOLEAN)
            <x-ui.checkbox :name="$field" :label="$label" :hint="OptionFields::hint($field)" :checked="($values[$field] ?? false) === true" />
            @break
        @case (OptionFields::PERCENT)
            <div class="max-w-xs">
                <x-ui.input :name="$field" :label="$label" :hint="OptionFields::hint($field)" :value="ParameterValues::percentInput($values[$field] ?? null)" suffix="%" inputmode="decimal" autocomplete="off" />
            </div>
            @break
        @default
            <x-ui.select :name="$field" :label="$label" :hint="OptionFields::hint($field)" :options="OptionFields::choices($field)" :value="$values[$field] ?? null" :required="true" />
    @endswitch
@endforeach
