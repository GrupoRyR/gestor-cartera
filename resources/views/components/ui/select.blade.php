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

    $multiple = (bool) $attributes->get('multiple');

    $error = isset($errors) ? $errors->first($key) : '';
    $hintId = filled($hint) ? "{$id}-hint" : null;
    $errorId = filled($error) ? "{$id}-error" : null;
    $describedBy = implode(' ', array_filter([$attributes->get('aria-describedby'), $hintId, $errorId]));
@endphp

<div data-part="field" class="flex min-w-0 flex-col gap-1.5" @if ($errorId) data-invalid @endif>
    <label for="{{ $id }}" @if ($labelHidden) data-visually-hidden @endif class="text-[0.8125rem] leading-snug font-semibold text-fg">{{ $label }}</label>

    <div data-part="control" class="relative flex rounded-[10px] border border-line-strong bg-surface transition-[border-color,box-shadow] has-disabled:bg-subtle has-disabled:opacity-70">
        <select {{ $attributes->except('aria-describedby')->merge([
            'id' => $id,
            'name' => $name,
            'required' => (bool) $required,
            'aria-invalid' => $errorId ? 'true' : null,
            'aria-describedby' => $describedBy !== '' ? $describedBy : null,
        ])->class([
            'w-full min-w-0 appearance-none rounded-[10px] bg-transparent text-base text-fg disabled:cursor-not-allowed md:text-[0.9375rem]',
            'min-h-11 py-2 ps-3 pe-10' => ! $multiple,
            'p-1.5 [&>option]:rounded-md [&>option]:px-2 [&>option]:py-1.5 [&>option:checked]:bg-brand-tint [&>option:checked]:font-semibold [&>option:checked]:text-brand-ink' => $multiple,
        ]) }}>
            @if ($placeholder !== null)
                <option value="">{{ $placeholder }}</option>
            @endif

            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
            @endforeach
        </select>

        @unless ($multiple)
            <x-ui.icon name="chevron-down" class="pointer-events-none absolute end-3 top-1/2 size-[1.125rem] -translate-y-1/2 text-fg-muted" />
        @endunless
    </div>

    @if ($hintId)
        <p id="{{ $hintId }}" data-part="hint" class="text-[0.8125rem] leading-snug text-fg-muted">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" data-part="error" class="text-[0.8125rem] leading-snug font-semibold text-status-failure">{{ $error }}</p>
    @endif
</div>
