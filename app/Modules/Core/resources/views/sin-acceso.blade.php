@use('Illuminate\Support\Facades\Route')

{{--
    GET /sin-acceso (core.sin-acceso): a signed-in user without a role (Part
    A.5). The guest layout keeps the neutral palette. The sign-out button
    appears once Core registers core.logout.
--}}
<x-layouts.guest :title="__('core::acceso.sin_acceso.pagina')">
    <x-ui.page-header
        :title="__('core::acceso.sin_acceso.titulo')"
        :description="__('core::acceso.sin_acceso.detalle')"
    />

    <x-ui.card>
        <p>{{ __('core::acceso.sin_acceso.cuenta', ['email' => $email]) }}</p>

        @if (Route::has('core.logout'))
            <form method="POST" action="{{ route('core.logout') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">{{ __('core::acceso.sin_acceso.salir') }}</x-ui.button>
            </form>
        @endif
    </x-ui.card>
</x-layouts.guest>
