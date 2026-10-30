@use('App\Modules\Core\Contracts\Data\BrandPalette')

{{--
    Layout of pages without a session: sign-in and error pages (C.5). Always
    the neutral palette and no navigation. Before signing in nobody has a
    company yet, and brand v2 never shows the two companies together, so the
    dark header carries the app name instead of a company logo.

    Extra attributes go to <body>.
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="es" data-brand="neutral">
<head>
    @include('components.layouts.partials.head', ['title' => $title, 'palette' => BrandPalette::neutral(), 'brand' => 'neutral'])
</head>
<body {{ $attributes->class('min-h-dvh bg-ground font-sans text-brand-text antialiased') }}>
    <div class="flex min-h-dvh flex-col">
        <a href="#contenido" data-part="skip-link" class="sr-only z-50 rounded-control bg-surface px-4 py-3 font-semibold shadow-pop focus:not-sr-only focus:fixed focus:start-3 focus:top-3">Saltar al contenido</a>

        <header data-part="header" class="bg-brand-head px-4 text-brand-head-fg md:px-8">
            <p data-part="brand" class="mx-auto flex min-h-16 max-w-lg items-center gap-3">
                <x-ui.icon name="wallet" />
                <span class="text-lg leading-tight font-bold">{{ config('app.name') }}</span>
            </p>
        </header>

        <main id="contenido" tabindex="-1" class="flex w-full flex-1 flex-col px-4 pt-6 pb-[calc(2rem+env(safe-area-inset-bottom))] outline-none md:px-8 md:pt-10 md:pb-[calc(2.5rem+env(safe-area-inset-bottom))]">
            <div class="mx-auto w-full max-w-lg rounded-card border border-line bg-surface p-6 md:p-8 [&>header]:pb-4 [&>p]:mb-6 [&>p]:text-fg-soft">
                @if (filled(session('status')))
                    <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
                @endif

                @if (filled(session('error')))
                    <x-ui.alert tone="danger" class="mb-6">{{ session('error') }}</x-ui.alert>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
