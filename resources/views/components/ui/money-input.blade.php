{{--
    Money amount field: an <x-ui.input> that shows pesos the Colombian way
    while the user types (1.234.567,50), through resources/js/money-input.js.

    The contract with the server is the clean number: `value` (and old input)
    is digits with an optional dot and up to $decimals decimals (1234567.5),
    never a formatted figure, and the script sends the field back the same
    way when the form is submitted (formdata event). Without the script the
    user types digits and the server gets them as they are, so no validation
    rule changes. $decimals: 0 (whole pesos, numeric keypad) to 2 (cents,
    decimal keypad). Every other prop and attribute goes to <x-ui.input>.
--}}
@props([
    'name',
    'label',
    'value' => null,
    'decimals' => 0,
    'hint' => null,
    'required' => false,
    'labelHidden' => false,
    'prefix' => '$',
    'suffix' => null,
    'id' => null,
])

@php
    if (! is_int($decimals) || $decimals < 0 || $decimals > 2) {
        throw new InvalidArgumentException('<x-ui.money-input> takes 0 to 2 decimals.');
    }
@endphp

<x-ui.input
    :name="$name"
    :label="$label"
    type="text"
    :value="$value"
    :hint="$hint"
    :required="$required"
    :label-hidden="$labelHidden"
    :prefix="$prefix"
    :suffix="$suffix"
    :id="$id"
    :inputmode="$decimals > 0 ? 'decimal' : 'numeric'"
    autocomplete="off"
    data-money-input
    :data-decimals="$decimals"
    {{ $attributes }}
/>
