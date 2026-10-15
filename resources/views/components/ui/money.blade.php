@use('App\Support\Formatting\Money')

{{--
    Money amount: $ 1.234.567 (C.5). Formatting rules live in
    App\Support\Formatting\Money; the no-break space keeps the sign with the
    digits. Tables line the figures up with tabular numbers.
--}}
@props(['value', 'currency' => 'COP', 'decimals' => null, 'fallback' => '—'])

@php
    $formatted = Money::format($value, $currency, $decimals, $fallback);
    // The fallback is shown for empty values, so only a formatted amount can be negative.
    $negative = $value !== null && $value !== '' && str_starts_with($formatted, '-');
@endphp

<span {{ $attributes->merge(['data-negative' => $negative ? 'true' : null])->class('whitespace-nowrap') }}>{{ $formatted }}</span>@php /* Ending on a PHP tag drops the final newline: no stray space before "." or "," after the component. */ @endphp
