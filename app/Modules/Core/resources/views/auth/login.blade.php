{{--
    GET /login (core.login): the sign-in page. Guests only, on the neutral
    guest layout, which also shows the flashed status (after signing out) or
    error (a rejected or failed sign-in).
--}}
<x-layouts.guest :title="__('core::ingreso.titulo')">
    <x-ui.page-header
        :title="__('core::ingreso.encabezado')"
        :description="__('core::ingreso.detalle')"
    />

    <x-ui.link-button :href="route('core.auth.redirect')">{{ __('core::ingreso.boton') }}</x-ui.link-button>
</x-layouts.guest>
