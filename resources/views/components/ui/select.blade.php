@use('App\Support\Formatting\FieldName')

{{--
    Labelled <select> (C.5). Options are value => label, e.g.
    ClientStatus::options(); the value may be an enum case. Old input wins
    over value after a failed submit. Extra attributes go to <select>.
--}}
@props([
    'name',
    'label',
    'options',
    'value' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
    'labelHidden' => false,
    'id' => null,
])

@php
    $key = FieldName::toKey($name);
    $id ??= FieldName::toId($name);

    // A list of values supports <select multiple>.
    $current = old($key, $value);
    $selected = array_map(
        fn ($item): string => (string) ($item instanceof BackedEnum ? $item->value : $item),
        is_array($current) ? $current : ($current === null ? [] : [$current]),
    );

    $error = isset($errors) ? $errors->first($key) : '';
    $hintId = filled($hint) ? "{$id}-hint" : null;
    $errorId = filled($error) ? "{$id}-error" : null;
    $describedBy = implode(' ', array_filter([$attributes->get('aria-describedby'), $hintId, $errorId]));
@endphp

<div data-part="field" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif>{{ $label }}</label>

    <select {{ $attributes->except('aria-describedby')->merge([
        'id' => $id,
        'name' => $name,
        'required' => (bool) $required,
        'aria-invalid' => $errorId ? 'true' : null,
        'aria-describedby' => $describedBy !== '' ? $describedBy : null,
    ]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error">{{ $error }}</p>
    @endif
</div>
