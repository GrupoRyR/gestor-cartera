@use('App\Support\Formatting\FieldName')

{{--
    Labelled text input (C.5). The id comes from the name when not given;
    old input wins over value after a failed submit (never for passwords);
    the validation error is read by the dot key of the name
    (clientes[0][email] → clientes.0.email). Extra attributes go to <input>.
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'labelHidden' => false,
    'prefix' => null,
    'suffix' => null,
    'id' => null,
])

@php
    $key = FieldName::toKey($name);
    $id ??= FieldName::toId($name);
    $value = $type === 'password' ? null : old($key, $value);

    $error = isset($errors) ? $errors->first($key) : '';
    $hintId = filled($hint) ? "{$id}-hint" : null;
    $errorId = filled($error) ? "{$id}-error" : null;
    $describedBy = implode(' ', array_filter([$attributes->get('aria-describedby'), $hintId, $errorId]));
@endphp

<div data-part="field" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif>{{ $label }}</label>

    <div data-part="control">
        @if (filled($prefix))
            <span data-part="prefix">{{ $prefix }}</span>
        @endif

        <input {{ $attributes->except('aria-describedby')->merge([
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'value' => $value,
            'required' => (bool) $required,
            'aria-invalid' => $errorId ? 'true' : null,
            'aria-describedby' => $describedBy !== '' ? $describedBy : null,
        ]) }}>

        @if (filled($suffix))
            <span data-part="suffix">{{ $suffix }}</span>
        @endif
    </div>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error">{{ $error }}</p>
    @endif
</div>
