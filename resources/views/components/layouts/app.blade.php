@use('App\Modules\Core\Contracts\CurrentCompany')
@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Support\Navigation\NavRegistry')
@use('App\View\Components\Support\GalleryPreview')
@use('Illuminate\Support\Facades\Route')
@use('Illuminate\Support\Str')

{{--
    Layout of every signed-in page (C.5): header with the app, the company
    selector and the account menu; sidebar on desktop (a drawer below 1024
    px); bottom bar on phones with the items a module marks mobile: true.

    Extra attributes go to <body>.
--}}
@props(['title' => null])

@php
    $user = auth()->user();

    // Core binds CurrentCompany in phase 0b. Until then, and for guests, the
    // neutral palette applies.
    $currentCompany = $user !== null && app()->bound(CurrentCompany::class) ? app(CurrentCompany::class) : null;
    $palette = $currentCompany?->palette() ?? BrandPalette::neutral();
    $activeCompany = $currentCompany?->active();
    $brand = $activeCompany !== null ? Str::lower($activeCompany->code->value) : 'neutral';

    $companies = $currentCompany?->available() ?? [];
    $companyOptions = [];
    foreach ($companies as $company) {
        $companyOptions[$company->code->value] = $company->name;
    }
    $companyOptions['grupo'] = 'Grupo RYR';
    $selectedCompany = $activeCompany?->code->value ?? 'grupo';
    $companyName = $activeCompany?->name ?? ($currentCompany?->isGroup() ? 'Grupo RYR' : null);
    $companyShort = $activeCompany?->code->value ?? ($currentCompany?->isGroup() ? 'Grupo' : null);

    // The component gallery previews the selector with GET requests to itself
    // (App\View\Components\Support\GalleryPreview); real pages post to Core.
    $preview = app()->bound(GalleryPreview::class) ? app(GalleryPreview::class) : null;
    $selectorAction = $preview?->url ?? (Route::has('core.empresa.cambiar') ? route('core.empresa.cambiar') : null);
    $showCompanySelector = $selectorAction !== null && count($companies) > 1;

    // One visibility pass for the sidebar and the bottom bar: each route and
    // permission is checked once per page.
    ['groups' => $groups, 'mobile' => $mobileItems] = app(NavRegistry::class)->menuFor($user);

    // Badge callbacks may query the database: run each one once.
    $badges = [];
    foreach ($groups as $items) {
        foreach ($items as $item) {
            $badges[$item->key] = $item->badgeCount();
        }
    }

    $initials = $user !== null
        ? Str::of((string) $user->name)->squish()->explode(' ')->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->implode('')
        : '';

    $hasSidebar = $groups !== [];
    $hasBottomBar = $mobileItems !== [];
@endphp

<!DOCTYPE html>
<html lang="es" data-brand="{{ $brand }}">
<head>
    @include('components.layouts.partials.head', ['title' => $title, 'palette' => $palette, 'brand' => $brand])
</head>
<body {{ $attributes->class('min-h-dvh bg-ground font-sans text-fg antialiased') }}>
    <div
        x-data="{ menuOpen: false }"
        x-on:keydown.escape.window="menuOpen = false"
        x-on:resize.window.debounce.150ms="if (window.matchMedia('(min-width: 64rem)').matches) menuOpen = false"
        class="flex min-h-dvh flex-col"
    >
        <a href="#contenido" data-part="skip-link" class="sr-only z-50 rounded-control bg-surface px-4 py-3 font-semibold shadow-pop focus:not-sr-only focus:fixed focus:start-3 focus:top-3">Saltar al contenido</a>

        <header data-part="header" class="relative z-30 border-b border-line bg-surface md:sticky md:top-0">
            <div class="flex min-h-16 flex-wrap items-center gap-2 px-3 py-2.5 md:gap-3 md:px-6">
                @if ($hasSidebar)
                    <button
                        type="button"
                        class="inline-flex size-touch shrink-0 items-center justify-center rounded-[10px] text-fg-soft hover:bg-subtle hover:text-fg lg:hidden"
                        aria-controls="menu-principal"
                        x-bind:aria-expanded="menuOpen.toString()"
                        aria-expanded="false"
                        x-on:click="menuOpen = true"
                    >
                        <x-ui.icon name="menu" />
                        <span data-visually-hidden>Abrir el menú</span>
                    </button>
                @endif

                <a href="{{ url('/') }}" data-part="brand" class="me-auto flex min-h-touch min-w-0 items-center gap-2.5 rounded-control pe-2 text-fg no-underline">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-brand-button text-brand-on-button">
                        <x-ui.icon name="wallet" />
                    </span>
                    <span class="truncate font-display text-lg leading-tight font-bold tracking-[-0.02em]">
                        <span class="sm:hidden">Cartera</span><span class="max-sm:hidden">{{ config('app.name') }}</span>
                    </span>
                </a>

                @if ($showCompanySelector)
                    <details
                        data-part="company-switcher"
                        class="group/company shrink-0 sm:relative"
                        x-data
                        x-on:click.outside="$el.open = false"
                        x-on:keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()"
                    >
                        <summary class="flex min-h-touch cursor-pointer items-center gap-2 rounded-full bg-brand-tint ps-3 pe-2.5 text-sm font-semibold text-brand-ink select-none hover:brightness-[0.97] dark:hover:brightness-110">
                            <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full bg-brand-primary dark:bg-brand-ink"></span>
                            <span data-visually-hidden>Empresa activa: </span>
                            <span class="max-sm:sr-only">{{ $companyName }}</span>
                            <span class="sm:hidden" aria-hidden="true">{{ $companyShort }}</span>
                            <x-ui.icon name="chevron-down" class="size-4 transition-transform group-open/company:rotate-180" />
                        </summary>

                        <form
                            method="{{ $preview !== null ? 'GET' : 'POST' }}"
                            action="{{ $selectorAction }}"
                            data-part="company-selector"
                            class="absolute inset-x-3 top-full z-40 mt-1 flex flex-col gap-3 rounded-card border border-line bg-surface p-4 shadow-pop sm:inset-x-auto sm:end-0 sm:mt-2 sm:w-72"
                        >
                            @if ($preview === null)
                                @csrf
                            @endif
                            <x-ui.select name="empresa" label="Empresa" :options="$companyOptions" :value="$selectedCompany" hint="La marca y los datos cambian a la empresa que elijas." />
                            <x-ui.button type="submit" variant="primary" class="w-full">Cambiar</x-ui.button>
                        </form>
                    </details>
                @elseif ($companyName !== null)
                    <p class="flex min-h-touch shrink-0 items-center gap-2 rounded-full bg-brand-tint px-3 text-sm font-semibold text-brand-ink">
                        <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full bg-brand-primary dark:bg-brand-ink"></span>
                        <span class="max-sm:sr-only">{{ $companyName }}</span>
                        <span class="sm:hidden" aria-hidden="true">{{ $companyShort }}</span>
                    </p>
                @endif

                @if ($user !== null)
                    <details
                        data-part="account"
                        class="shrink-0 sm:relative"
                        x-data
                        x-on:click.outside="$el.open = false"
                        x-on:keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()"
                    >
                        <summary class="flex size-touch cursor-pointer items-center justify-center rounded-full select-none hover:bg-subtle">
                            <span aria-hidden="true" class="grid size-9 place-items-center rounded-full bg-brand-tint text-sm font-bold text-brand-ink">{{ $initials !== '' ? $initials : '?' }}</span>
                            <span data-visually-hidden>Tu cuenta</span>
                        </summary>

                        <div data-part="user" class="absolute inset-x-3 top-full z-40 mt-1 rounded-card border border-line bg-surface p-2 shadow-pop sm:inset-x-auto sm:end-0 sm:mt-2 sm:w-72">
                            <div class="px-3 pt-2 pb-3">
                                <p class="font-bold leading-snug [overflow-wrap:anywhere]">{{ $user->name }}</p>
                                @if (filled($user->email))
                                    <p class="text-[0.8125rem] text-fg-muted [overflow-wrap:anywhere]">{{ $user->email }}</p>
                                @endif
                            </div>

                            @if (Route::has('core.logout'))
                                <form method="POST" action="{{ route('core.logout') }}" data-part="logout" class="border-t border-line pt-2">
                                    @csrf
                                    <x-ui.button type="submit" variant="ghost" icon="logout" class="w-full justify-start px-3">Salir</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </details>
                @endif
            </div>
        </header>

        <div class="flex flex-1">
            @if ($hasSidebar)
                <div
                    x-cloak
                    x-show="menuOpen"
                    x-transition.opacity
                    x-on:click="menuOpen = false"
                    class="fixed inset-0 z-40 bg-black/45 lg:hidden"
                    aria-hidden="true"
                ></div>

                <nav
                    id="menu-principal"
                    aria-label="Navegación principal"
                    data-part="sidebar"
                    x-trap.inert.noscroll="menuOpen"
                    x-bind:data-open="menuOpen"
                    class="invisible fixed inset-y-0 start-0 z-50 flex w-[min(18rem,86vw)] -translate-x-full flex-col overflow-y-auto border-e border-line bg-surface px-3 pt-3 pb-6 transition-[translate,visibility] duration-200 data-[open=true]:visible data-[open=true]:translate-x-0 lg:visible lg:sticky lg:top-16 lg:z-auto lg:h-[calc(100dvh-4rem)] lg:w-64 lg:shrink-0 lg:translate-x-0 lg:px-3.5 lg:pt-4 lg:transition-none"
                >
                    <div class="mb-1 flex justify-end lg:hidden">
                        <button type="button" x-on:click="menuOpen = false" class="inline-flex size-touch items-center justify-center rounded-[10px] text-fg-soft hover:bg-subtle hover:text-fg">
                            <x-ui.icon name="close" />
                            <span data-visually-hidden>Cerrar el menú</span>
                        </button>
                    </div>

                    @foreach ($groups as $group => $items)
                        @php($groupId = 'nav-group-'.$loop->index)
                        <h2 id="{{ $groupId }}" @class(['px-3 pb-1.5 font-sans text-xs font-bold tracking-normal text-fg-muted', 'pt-5' => ! $loop->first, 'pt-1' => $loop->first])>{{ $group }}</h2>
                        <ul aria-labelledby="{{ $groupId }}" class="flex flex-col gap-0.5">
                            @foreach ($items as $item)
                                @php($active = $item->isActive())
                                <li>
                                    <a href="{{ $item->url() }}" @if ($active) aria-current="page" @endif @class([
                                        'flex min-h-touch items-center gap-3 rounded-[10px] px-3 py-2 text-sm leading-snug no-underline transition-colors',
                                        'bg-brand-tint font-bold text-brand-ink' => $active,
                                        'font-semibold text-fg-soft hover:bg-subtle hover:text-fg' => ! $active,
                                    ])>
                                        @if ($item->icon !== null)
                                            <x-ui.icon :name="$item->icon" />
                                        @endif
                                        <span class="min-w-0 flex-1">{{ $item->label }}</span>
                                        @if ($badges[$item->key] !== null)
                                            <span data-part="badge" class="min-w-6 rounded-full bg-brand-button px-1.5 py-0.5 text-center text-xs font-bold text-brand-on-button tabular-nums">{{ $badges[$item->key] }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </nav>
            @endif

            <main id="contenido" tabindex="-1" @class([
                'mx-auto w-full max-w-[90rem] min-w-0 flex-1 px-4 pt-6 outline-none md:px-8 md:pt-8 md:pb-12 lg:px-10',
                'pb-[calc(6rem+env(safe-area-inset-bottom))]' => $hasBottomBar,
                'pb-10' => ! $hasBottomBar,
            ])>
                @if (filled(session('status')))
                    <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
                @endif

                @if (filled(session('error')))
                    <x-ui.alert tone="danger" class="mb-6">{{ session('error') }}</x-ui.alert>
                @endif

                {{ $slot }}
            </main>
        </div>

        @if ($hasBottomBar)
            <nav aria-label="Accesos rápidos" data-part="bottom-bar" class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden">
                <ul class="flex overflow-x-auto [scrollbar-width:none]">
                    @foreach ($mobileItems as $item)
                        @php($active = $item->isActive())
                        <li class="min-w-[4.5rem] flex-1">
                            <a href="{{ $item->url() }}" @if ($active) aria-current="page" @endif @class([
                                'relative flex min-h-16 flex-col items-center justify-center gap-1 px-1.5 py-2 text-center text-xs leading-tight no-underline',
                                'font-bold text-brand-ink' => $active,
                                'font-semibold text-fg-muted' => ! $active,
                            ])>
                                @if ($item->icon !== null)
                                    <span @class(['grid h-7 w-12 place-items-center rounded-full', 'bg-brand-tint' => $active])>
                                        <x-ui.icon :name="$item->icon" />
                                    </span>
                                @endif
                                <span>{{ $item->label }}</span>
                                @if ($badges[$item->key] !== null)
                                    {{-- After the label in reading order ("Respuestas 4"); drawn on the icon's corner. --}}
                                    <span data-part="badge" class="absolute top-1 start-[calc(50%+0.5rem)] min-w-5 rounded-full bg-brand-button px-1 text-center text-[0.6875rem] leading-[1.125rem] font-bold text-brand-on-button tabular-nums">{{ $badges[$item->key] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</body>
</html>
