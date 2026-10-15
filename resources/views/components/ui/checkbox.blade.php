@use('App\Support\Formatting\FieldName')

{{--
    Labelled checkbox (C.5). The id comes from name and value, so every
    checkbox of a list (name="clientes[]") gets its own. After a failed
    submit (the session has old input) the state comes from old input, as in
    the other fields, even over an explicit checked: a key missing from old
    input means unchecked, because browsers do not send unchecked boxes.
    Otherwise the explicit checked applies (null means unchecked). Extra
    attributes go to <input>.
--}}
@props(['name', 'label', 'value' => '1', 'checked' => null, 'hint' => null, 'id' => null])

@php
    $key = FieldName::toKey($name);
    $value = (string) ($value instanceof BackedEnum ? $value->value : $value);
    $id ??= FieldName::toId($name, $value);

    if (request()->hasSession() && request()->session()->hasOldInput()) {
        $old = old($key);
        $checked = is_array($old)
            ? in_array($value, array_map(fn ($item): string => (string) $item, $old), true)
            : $old !== null && (string) $old === $value;
    }

    $error = isset($errors) ? $errors->first($key) : '';
    $hintId = filled($hint) ? "{$id}-hint" : null;
    $errorId = filled($error) ? "{$id}-error" : null;
    $describedBy = implode(' ', array_filter([$attributes->get('aria-describedby'), $hintId, $errorId]));
@endphp

<div data-part="field" @if ($errorId) data-invalid @endif>
    <input {{ $attributes->except('aria-describedby')->merge([
        'type' => 'checkbox',
        'id' => $id,
        'name' => $name,
        'value' => $value,
        'checked' => (bool) $checked,
        'aria-invalid' => $errorId ? 'true' : null,
        'aria-describedby' => $describedBy !== '' ? $describedBy : null,
    ]) }}>
    <label for="{{ $id }}">{{ $label }}</label>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error">{{ $error }}</p>
    @endif
</div>
