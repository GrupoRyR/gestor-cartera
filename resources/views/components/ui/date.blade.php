@use('App\Support\Formatting\DateFormat')

{{--
    Date as DD/MM/AAAA, or DD/MM/AAAA HH:MM with :time="true" (C.5), in
    Colombian time. Empty values show the fallback without a <time> element.
--}}
@props(['value', 'time' => false, 'fallback' => '—'])

@if ($value === null || (is_string($value) && trim($value) === ''))
    <span {{ $attributes }}>{{ $fallback }}</span>
@else
    <time {{ $attributes->merge(['datetime' => DateFormat::iso($value, (bool) $time)])->class('whitespace-nowrap') }}>{{ $time ? DateFormat::dateTime($value) : DateFormat::date($value) }}</time>
@endif
