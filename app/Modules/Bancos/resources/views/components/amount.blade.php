{{--
    An amount in its own currency: pesos and dollars use <x-ui.money>; any
    other ISO code (a movement can say so) is written as "1.234,50 EUR".
    Pesos show their cents only when there are some, so a round amount reads
    "$ 1.250.000" and a bank credit of 1.250.000,50 is not rounded away.
    Props: value (decimal string or number), currency (ISO code).
--}}
@props(['value', 'currency' => 'COP'])

@php($cents = round(fmod(abs((float) $value), 1), 2) > 0)

@if (in_array($currency, ['COP', 'USD'], true))
    <x-ui.money :value="$value" :currency="$currency" :decimals="$currency === 'COP' && $cents ? 2 : null" {{ $attributes }} />
@else
    <span {{ $attributes }}>{{ number_format((float) $value, 2, ',', '.') }} {{ $currency }}</span>
@endif
