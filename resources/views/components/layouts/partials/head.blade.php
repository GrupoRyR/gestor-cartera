{{--
    Shared <head> content of <x-layouts.app> and <x-layouts.guest>. Not part
    of the C.5 contract: include it with $title (?string), $palette
    (App\Modules\Core\Contracts\Data\BrandPalette) and, optionally, $brand
    ('sgx', 'sdc' or 'neutral'; neutral by default), which picks the
    favicon and the home-screen icon of the active company.

    The --brand-* variables are the only source of company colors (A.6).
    The app has one manifest for every company and role (A.6); its icon is
    the neutral one.
--}}
@php
    $brand = in_array($brand ?? null, ['sgx', 'sdc'], true) ? $brand : 'neutral';
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<title>{{ filled($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="icon" href="{{ asset("icons/{$brand}/favicon.svg") }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset("icons/{$brand}/apple-touch-icon.png") }}">
<meta name="apple-mobile-web-app-title" content="Cartera">
{{-- The browser bar takes the color of the header (--brand-head). --}}
<meta name="theme-color" content="{{ $palette->head }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ $palette->darkHead }}" media="(prefers-color-scheme: dark)">

@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
    :root {
@foreach ($palette->toCssVariables() as $variable => $color)
        {{ $variable }}: {{ $color }};
@endforeach
    }

    @media (prefers-color-scheme: dark) {
        :root {
@foreach ($palette->toCssVariables(dark: true) as $variable => $color)
            {{ $variable }}: {{ $color }};
@endforeach
        }
    }
</style>
