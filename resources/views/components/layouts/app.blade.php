@use('App\Modules\Core\Contracts\CurrentCompany')
@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Modules\Core\Contracts\Enums\CompanyCode')
@use('App\Support\Navigation\NavRegistry')
@use('App\View\Components\Support\GalleryPreview')
@use('Illuminate\Support\Facades\Route')
@use('Illuminate\Support\Str')

{{--
    Layout of every signed-in page (C.5): dark brand header with the logo of
    the active company, the company switcher and the account menu (skill
    frontend-marca-sgx-sydc-v2); sidebar on desktop (a drawer below 1024
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

    // The switcher shows a short label; the full name is the accessible name
    // and starts with what people see.
    $companySwitch = [];
    foreach ($companyOptions as $code => $name) {
        $label = match ($code) {
            'SGX' => 'Segurex',
            'grupo' => 'Grupo',
            default => $code,
        };
        $companySwitch[] = [
            'code' => $code,
            'label' => $label,
            'name' => Str::startsWith($name, $label) ? $name : "{$label} ({$name})",
            'pressed' => $code === $selectedCompany,
        ];
    }

    // Brand v2 logos: the white Segurex logo sits on the dark header; the SDC
    // logo goes on a white plate. The group view has no company logo.
    $logo = match ($activeCompany?->code) {
        CompanyCode::SGX => ['src' => asset('icons/marcas/segurex-blanco.png'), 'width' => 186, 'height' => 30, 'plate' => false],
        CompanyCode::SDC => ['src' => asset('icons/marcas/sdc.png'), 'width' => 98, 'height' => 24, 'plate' => true],
        default => null,
    };

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
<body {{ $attributes->class('min-h-dvh bg-ground font-sans text-brand-text antialiased') }}>
    <div
        x-data="{ menuOpen: false }"
        x-on:keydown.escape.window="menuOpen = false"
        x-on:resize.window.debounce.150ms="if (window.matchMedia('(min-width: 64rem)').matches) menuOpen = false"
        class="flex min-h-dvh flex-col"
    >
        <a href="#contenido" data-part="skip-link" class="sr-only z-50 rounded-control bg-surface px-4 py-3 font-semibold shadow-pop focus:not-sr-only focus:fixed focus:start-3 focus:top-3">Saltar al contenido</a>

        <header data-part="header" class="relative z-30 bg-brand-head text-brand-head-fg md:sticky md:top-0">
            {{-- Below 640 px the switcher takes a second row: it does not fit next to the logo on a phone. --}}
            <div class="flex min-h-16 flex-wrap items-center gap-x-2 gap-y-1.5 px-3 py-1.5 max-sm:pb-2.5 md:gap-x-3 md:px-6">
                @if ($hasSidebar)
                    <button
                        type="button"
                        class="inline-flex size-touch shrink-0 items-center justify-center rounded-[10px] text-brand-head-fg hover:bg-brand-switch-track focus-visible:outline-brand-head-fg lg:hidden"
                        aria-controls="menu-principal"
                        x-bind:aria-expanded="menuOpen.toString()"
                        aria-expanded="false"
                        x-on:click="menuOpen = true"
                    >
                        <x-ui.icon name="menu" />
                        <span data-visually-hidden>Abrir el menú</span>
                    </button>
                @endif

                <a href="{{ url('/') }}" data-part="brand" class="me-auto flex min-h-touch min-w-0 items-center gap-3 rounded-control pe-2 text-brand-head-fg no-underline focus-visible:outline-brand-head-fg">
                    @if ($logo === null)
                        <x-ui.icon name="wallet" />
                        <span class="truncate text-lg leading-tight font-bold">
                            <span class="sm:hidden">Cartera</span><span class="max-sm:hidden">{{ config('app.name') }}</span>
                        </span>
                    @else
                        @if ($logo['plate'])
                            <span data-part="logo-plate" class="flex shrink-0 items-center rounded-full bg-brand-plate px-2.5 py-1 sm:px-3.5">
                                <img src="{{ $logo['src'] }}" alt="{{ $activeCompany->name }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" class="block h-[18px] w-auto sm:h-6">
                            </span>
                        @else
                            <img src="{{ $logo['src'] }}" alt="{{ $activeCompany->name }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" class="block h-[22px] w-auto shrink-0 sm:h-[30px]">
                        @endif
                        {{-- Hidden below 768 px so the logo, the switcher and the account fit one row. --}}
                        <span data-part="app-name" class="truncate border-s border-brand-head-line ps-3 text-sm leading-tight font-bold max-md:hidden">{{ config('app.name') }}</span>
                    @endif
                </a>

                @if ($showCompanySelector)
                    <form
                        method="{{ $preview !== null ? 'GET' : 'POST' }}"
                        action="{{ $selectorAction }}"
                        data-part="company-selector"
                        class="shrink-0 max-sm:order-last max-sm:basis-full"
                    >
                        @if ($preview === null)
                            @csrf
                        @endif
                        <div role="group" aria-label="Empresa" class="flex gap-0.5 rounded-full bg-brand-switch-track p-[3px]">
                            @foreach ($companySwitch as $option)
                                <button
                                    type="submit"
                                    name="empresa"
                                    value="{{ $option['code'] }}"
                                    aria-pressed="{{ $option['pressed'] ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex min-h-touch cursor-pointer items-center justify-center rounded-full px-2.5 text-[0.8125rem] font-bold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-head-fg max-sm:flex-1 sm:px-3.5 sm:text-sm',
                                        'bg-brand-plate text-brand-switch-ink' => $option['pressed'],
                                        'text-brand-head-fg hover:bg-brand-switch-hover' => ! $option['pressed'],
                                    ])
                                    aria-label="{{ $option['name'] }}"
                                >{{ $option['label'] }}</button>
                            @endforeach
                        </div>
                    </form>
                @endif

                @if ($user !== null)
                    <details
                        data-part="account"
                        class="shrink-0 sm:relative"
                        x-data
                        x-on:click.outside="$el.open = false"
                        x-on:keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()"
                    >
                        <summary class="flex size-touch cursor-pointer items-center justify-center rounded-full select-none hover:bg-brand-switch-track focus-visible:outline-brand-head-fg">
                            <span aria-hidden="true" class="grid size-9 place-items-center rounded-full bg-brand-switch-track text-sm font-bold text-brand-head-fg">{{ $initials !== '' ? $initials : '?' }}</span>
                            <span data-visually-hidden>Tu cuenta</span>
                        </summary>

                        {{-- The panel is a light surface: it sets its own text color instead of the header's white. --}}
                        <div data-part="user" class="absolute inset-x-3 top-full z-40 mt-1 rounded-card border border-line bg-surface p-2 text-brand-text shadow-pop sm:inset-x-auto sm:end-0 sm:mt-2 sm:w-72">
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
