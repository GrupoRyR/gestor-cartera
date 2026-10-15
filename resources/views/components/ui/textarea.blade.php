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

<div data-part="field" class="flex min-w-0 flex-col gap-1.5" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif class="text-[0.8125rem] leading-snug font-semibold text-fg">{{ $label }}</label>

    <div data-part="control" class="flex rounded-[10px] border border-line-strong bg-surface transition-[border-color,box-shadow] has-disabled:bg-subtle has-disabled:opacity-70">
        <textarea {{ $attributes->except('aria-describedby')->merge([
            'id' => $id,
            'name' => $name,
            'rows' => (int) $rows,
            'required' => (bool) $required,
            'aria-invalid' => $errorId ? 'true' : null,
            'aria-describedby' => $describedBy !== '' ? $describedBy : null,
        ])->class('block w-full min-w-0 resize-y rounded-[10px] bg-transparent px-3 py-2.5 text-base leading-normal text-fg placeholder:text-fg-muted disabled:cursor-not-allowed md:text-[0.9375rem]') }}>{{ $value }}</textarea>
    </div>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint" class="text-[0.8125rem] leading-snug text-fg-muted">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error" class="text-[0.8125rem] leading-snug font-semibold text-status-failure">{{ $error }}</p>
    @endif
</div>
