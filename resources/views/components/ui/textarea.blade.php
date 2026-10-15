@use('App\Support\Formatting\FieldName')

{{--
    Labelled <textarea> (C.5). Same id, old input and error rules as
    <x-ui.input>. Extra attributes go to <textarea>.
--}}
@props([
    'name',
    'label',
    'value' => null,
    'rows' => 4,
    'hint' => null,
    'required' => false,
    'labelHidden' => false,
    'id' => null,
])

@php
    $key = FieldName::toKey($name);
    $id ??= FieldName::toId($name);
    $value = old($key, $value);

    $error = isset($errors) ? $errors->first($key) : '';
    $hintId = filled($hint) ? "{$id}-hint" : null;
    $errorId = filled($error) ? "{$id}-error" : null;
    $describedBy = implode(' ', array_filter([$attributes->get('aria-describedby'), $hintId, $errorId]));
@endphp

<div data-part="field" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif>{{ $label }}</label>

    <textarea {{ $attributes->except('aria-describedby')->merge([
        'id' => $id,
        'name' => $name,
        'rows' => (int) $rows,
        'required' => (bool) $required,
        'aria-invalid' => $errorId ? 'true' : null,
        'aria-describedby' => $describedBy !== '' ? $describedBy : null,
    ]) }}>{{ $value }}</textarea>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error">{{ $error }}</p>
    @endif
</div>
