@use('App\Modules\Core\Contracts\Data\BrandPalette')

{{--
    Layout of pages without a session: sign-in and error pages (C.5). Always
    the neutral palette and no navigation: before signing in nobody has a
    company yet, so the page shows the logos of both companies instead
    (skill ryr-design).

    Extra attributes go to <body>.
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="es" data-brand="neutral">
<head>
    @include('components.layouts.partials.head', ['title' => $title, 'palette' => BrandPalette::neutral(), 'brand' => 'neutral'])
</head>
<body {{ $attributes->class('min-h-dvh bg-ground font-sans text-fg antialiased') }}>
    <div class="flex min-h-dvh flex-col">
        <a href="#contenido" data-part="skip-link" class="sr-only z-50 rounded-control bg-surface px-4 py-3 font-semibold shadow-pop focus:not-sr-only focus:fixed focus:start-3 focus:top-3">Saltar al contenido</a>

        <header data-part="header" class="px-4 pt-6 md:px-8 md:pt-8">
            <p data-part="brand" class="mx-auto flex max-w-lg items-center gap-2.5">
                <span data-brand-surface class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-brand-button text-brand-on-button">
                    <x-ui.icon name="wallet" />
                </span>
                <span class="font-display text-lg leading-tight font-bold tracking-[-0.02em]">{{ config('app.name') }}</span>
            </p>
        </header>

        <main id="contenido" tabindex="-1" class="flex w-full flex-1 flex-col px-4 py-6 outline-none md:px-8 md:py-10">
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

        <footer class="px-4 pt-2 pb-[calc(2rem+env(safe-area-inset-bottom))] md:px-8">
            <div class="mx-auto flex max-w-lg flex-wrap items-center justify-center gap-x-8 gap-y-4">
                <picture>
                    <source srcset="{{ asset('icons/marcas/segurex-oscuro.svg') }}" media="(prefers-color-scheme: dark)">
                    <img src="{{ asset('icons/marcas/segurex.svg') }}" alt="Segurex Latam" width="150" height="24" class="h-6 w-auto">
                </picture>
                <picture>
                    <source srcset="{{ asset('icons/marcas/derivados-oscuro.svg') }}" media="(prefers-color-scheme: dark)">
                    <img src="{{ asset('icons/marcas/derivados.svg') }}" alt="Soluciones y Derivados" width="132" height="24" class="h-6 w-auto">
                </picture>
            </div>
        </footer>
    </div>
</body>
</html>
