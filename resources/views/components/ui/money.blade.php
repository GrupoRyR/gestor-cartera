@use('App\Support\Formatting\Money')

{{-- Money amount: $ 1.234.567 (C.5). Formatting rules live in App\Support\Formatting\Money. --}}
@props(['value', 'currency' => 'COP', 'decimals' => null, 'fallback' => '—'])

@php
    $formatted = Money::format($value, $currency, $decimals, $fallback);
    // The fallback is shown for empty values, so only a formatted amount can be negative.
    $negative = $value !== null && $value !== '' && str_starts_with($formatted, '-');
@endphp

<span {{ $attributes->merge(['data-negative' => $negative ? 'true' : null]) }}>{{ $formatted }}</span>
