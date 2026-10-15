<?php

namespace App\View\Components\Support;

/**
 * Classes shared by the form fields (<x-ui.input>, <x-ui.select>,
 * <x-ui.textarea> and <x-ui.checkbox>), so labels, hints, errors and the
 * box around a control look the same in every field. The focus and
 * invalid borders of the box live in resources/css/app.css
 * ([data-part='control']).
 */
final class FieldStyle
{
    public const string FIELD = 'flex min-w-0 flex-col gap-1.5';

    public const string LABEL = 'text-[0.8125rem] leading-snug font-semibold text-fg';

    public const string CONTROL = 'rounded-[10px] border border-line-strong bg-surface transition-[border-color,box-shadow] has-disabled:bg-subtle has-disabled:opacity-70';

    public const string HINT = 'text-[0.8125rem] leading-snug text-fg-muted';

    public const string ERROR = 'text-[0.8125rem] leading-snug font-semibold text-status-failure';

    /** Text inside the control: 16 px on phones so iOS does not zoom in. */
    public const string TEXT = 'bg-transparent text-base text-fg placeholder:text-fg-muted disabled:cursor-not-allowed md:text-[0.9375rem]';
}
