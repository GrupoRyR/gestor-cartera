@use('App\Support\Formatting\FieldName')
@use('App\View\Components\Support\FieldStyle')

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

<div data-part="field" class="{{ FieldStyle::FIELD }}" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif class="{{ FieldStyle::LABEL }}">{{ $label }}</label>

    <div data-part="control" class="flex {{ FieldStyle::CONTROL }}">
        <textarea {{ $attributes->except('aria-describedby')->merge([
            'id' => $id,
            'name' => $name,
            'rows' => (int) $rows,
            'required' => (bool) $required,
            'aria-invalid' => $errorId ? 'true' : null,
            'aria-describedby' => $describedBy !== '' ? $describedBy : null,
        ])->class('block w-full min-w-0 resize-y rounded-[10px] px-3 py-2.5 leading-normal '.FieldStyle::TEXT) }}>{{ $value }}</textarea>
    </div>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint" class="{{ FieldStyle::HINT }}">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error" class="{{ FieldStyle::ERROR }}">{{ $error }}</p>
    @endif
</div>
