@use('App\Support\Formatting\FieldName')

{{--
    Labelled text input (C.5). The id comes from the name when not given;
    old input wins over value after a failed submit (never for passwords);
    the validation error is read by the dot key of the name
    (clientes[0][email] → clientes.0.email). Extra attributes go to <input>.

    The box around the input ([data-part=control]) carries the border, the
    prefix and suffix, and the focus ring (resources/css/app.css). The text
    is 16 px on phones so iOS does not zoom in.
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

<div data-part="field" class="flex min-w-0 flex-col gap-1.5" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif class="text-[0.8125rem] leading-snug font-semibold text-fg">{{ $label }}</label>

    <div data-part="control" class="flex min-h-11 items-stretch overflow-hidden rounded-[10px] border border-line-strong bg-surface transition-[border-color,box-shadow] has-disabled:bg-subtle has-disabled:opacity-70">
        @if (filled($prefix))
            <span data-part="prefix" class="flex items-center ps-3 text-[0.9375rem] font-medium text-fg-muted select-none">{{ $prefix }}</span>
        @endif

        <input {{ $attributes->except('aria-describedby')->merge([
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'value' => $value,
            'required' => (bool) $required,
            'aria-invalid' => $errorId ? 'true' : null,
            'aria-describedby' => $describedBy !== '' ? $describedBy : null,
        ])->class([
            'min-w-0 flex-1 bg-transparent px-3 py-2 text-base text-fg placeholder:text-fg-muted disabled:cursor-not-allowed md:text-[0.9375rem]',
            'ps-2' => filled($prefix),
            'pe-2' => filled($suffix),
        ]) }}>

        @if (filled($suffix))
            <span data-part="suffix" class="flex items-center pe-3 text-[0.9375rem] font-medium text-fg-muted select-none">{{ $suffix }}</span>
        @endif
    </div>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint" class="text-[0.8125rem] leading-snug text-fg-muted">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error" class="text-[0.8125rem] leading-snug font-semibold text-status-failure">{{ $error }}</p>
    @endif
</div>
