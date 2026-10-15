@use('App\Modules\Core\Contracts\Data\BrandPalette')

{{--
    Layout of pages without a session: sign-in and error pages (C.5). Always
    the neutral palette and no navigation.

    Extra attributes go to <body>.
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="es">
<head>
    @include('components.layouts.partials.head', ['title' => $title, 'palette' => BrandPalette::neutral()])
</head>
<body {{ $attributes }}>
    <a href="#contenido" data-part="skip-link">Saltar al contenido</a>

    <header data-part="header">
        <p data-part="brand">{{ config('app.name') }}</p>
    </header>

    <main id="contenido" tabindex="-1">
        @if (filled(session('status')))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if (filled(session('error')))
            <x-ui.alert tone="danger">{{ session('error') }}</x-ui.alert>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
