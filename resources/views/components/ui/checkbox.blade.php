@use('App\Support\Formatting\FieldName')
@use('App\View\Components\Support\FieldStyle')

{{--
    Labelled checkbox (C.5). The id comes from name and value, so every
    checkbox of a list (name="clientes[]") gets its own. After a failed
    submit (the session has old input) the state comes from old input, as in
    the other fields, even over an explicit checked: a key missing from old
    input means unchecked, because browsers do not send unchecked boxes.
    Otherwise the explicit checked applies (null means unchecked). Extra
    attributes go to <input>.

    The whole row is the touch target (44 px high); the box takes the brand
    control color when checked.
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

<div data-part="field" class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 has-disabled:opacity-60 has-disabled:[&_label]:cursor-not-allowed" @if ($errorId) data-invalid @endif>
    <input {{ $attributes->except('aria-describedby')->merge([
        'type' => 'checkbox',
        'id' => $id,
        'name' => $name,
        'value' => $value,
        'checked' => (bool) $checked,
        'aria-invalid' => $errorId ? 'true' : null,
        'aria-describedby' => $describedBy !== '' ? $describedBy : null,
    ])->class('mt-3 size-5 shrink-0 cursor-pointer rounded accent-brand-control disabled:cursor-not-allowed aria-invalid:outline-2 aria-invalid:outline-offset-2 aria-invalid:outline-status-failure') }}>
    <label for="{{ $id }}" class="flex min-h-touch cursor-pointer items-center py-2 text-[0.9375rem] leading-snug font-medium text-fg">{{ $label }}</label>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint" class="col-start-2 {{ FieldStyle::HINT }}">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error" class="col-start-2 mt-0.5 {{ FieldStyle::ERROR }}">{{ $error }}</p>
    @endif
</div>
