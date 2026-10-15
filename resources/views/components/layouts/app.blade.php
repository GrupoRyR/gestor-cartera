@use('App\Modules\Core\Contracts\CurrentCompany')
@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Support\Navigation\NavRegistry')
@use('Illuminate\Support\Facades\Route')

{{--
    Layout of every signed-in page (C.5). Stub from phase 0a: phase 0b
    (Diseño) styles it but keeps its props and behaviour.

    Extra attributes go to <body>.
--}}
@props(['title' => null])

@php
    $user = auth()->user();

    // Core binds CurrentCompany in phase 0b. Until then, and for guests, the
    // neutral palette applies.
    $currentCompany = $user !== null && app()->bound(CurrentCompany::class) ? app(CurrentCompany::class) : null;
    $palette = $currentCompany?->palette() ?? BrandPalette::neutral();

    $companies = $currentCompany?->available() ?? [];
    $showCompanySelector = Route::has('core.empresa.cambiar') && count($companies) > 1;
    $companyOptions = [];
    foreach ($companies as $company) {
        $companyOptions[$company->code->value] = $company->name;
    }
    $companyOptions['grupo'] = 'Grupo RYR';
    $selectedCompany = $currentCompany?->active()?->code->value ?? 'grupo';

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
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    @include('components.layouts.partials.head', ['title' => $title, 'palette' => $palette])
</head>
<body {{ $attributes }}>
    <a href="#contenido" data-part="skip-link">Saltar al contenido</a>

    <header data-part="header">
        <a href="{{ url('/') }}" data-part="brand">{{ config('app.name') }}</a>

        @if ($showCompanySelector)
            <form method="POST" action="{{ route('core.empresa.cambiar') }}" data-part="company-selector">
                @csrf
                <x-ui.select name="empresa" label="Empresa" :options="$companyOptions" :value="$selectedCompany" />
                <x-ui.button type="submit" variant="secondary">Cambiar</x-ui.button>
            </form>
        @endif

        @if ($user !== null)
            <p data-part="user">{{ $user->name }}</p>

            @if (Route::has('core.logout'))
                <form method="POST" action="{{ route('core.logout') }}" data-part="logout">
                    @csrf
                    <x-ui.button type="submit" variant="ghost">Salir</x-ui.button>
                </form>
            @endif
        @endif
    </header>

    @if ($groups !== [])
        <nav aria-label="Navegación principal" data-part="sidebar">
            @foreach ($groups as $group => $items)
                @php($groupId = 'nav-group-'.$loop->index)
                <h2 id="{{ $groupId }}">{{ $group }}</h2>
                <ul aria-labelledby="{{ $groupId }}">
                    @foreach ($items as $item)
                        <li>
                            <a href="{{ $item->url() }}" @if ($item->isActive()) aria-current="page" @endif>
                                @if ($item->icon !== null)
                                    <x-ui.icon :name="$item->icon" />
                                @endif
                                <span>{{ $item->label }}</span>
                                @if ($badges[$item->key] !== null)
                                    <span data-part="badge">{{ $badges[$item->key] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </nav>
    @endif

    <main id="contenido" tabindex="-1">
        @if (filled(session('status')))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if (filled(session('error')))
            <x-ui.alert tone="danger">{{ session('error') }}</x-ui.alert>
        @endif

        {{ $slot }}
    </main>

    @if ($mobileItems !== [])
        <nav aria-label="Accesos rápidos" data-part="bottom-bar">
            <ul>
                @foreach ($mobileItems as $item)
                    <li>
                        <a href="{{ $item->url() }}" @if ($item->isActive()) aria-current="page" @endif>
                            @if ($item->icon !== null)
                                <x-ui.icon :name="$item->icon" />
                            @endif
                            <span>{{ $item->label }}</span>
                            @if ($badges[$item->key] !== null)
                                <span data-part="badge">{{ $badges[$item->key] }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
</body>
</html>
