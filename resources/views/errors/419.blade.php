{{-- HTTP 419 page, rendered by Laravel's exception handler. --}}
<x-layouts.guest title="Tu sesión expiró">
    <x-ui.page-header title="Tu sesión expiró" context="Error 419" />

    <p>Por seguridad, recarga la página e inténtalo de nuevo.</p>

    <x-ui.link-button :href="url('/')">Volver al inicio</x-ui.link-button>
</x-layouts.guest>
