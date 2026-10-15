{{--
    Shared <head> content of <x-layouts.app> and <x-layouts.guest>. Not part
    of the C.5 contract: include it with $title (?string) and $palette
    (App\Modules\Core\Contracts\Data\BrandPalette).

    The --brand-* variables are the only source of company colors (A.6).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ filled($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>
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
