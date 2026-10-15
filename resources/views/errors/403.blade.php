{{-- HTTP 403 page, rendered by Laravel's exception handler. --}}
<x-layouts.guest title="No tienes permiso para ver esta página">
    <x-ui.page-header title="No tienes permiso para ver esta página" context="Error 403" />

    <p>Si crees que deberías verla, pídele acceso a quien administra la plataforma.</p>

    <x-ui.link-button :href="url('/')">Volver al inicio</x-ui.link-button>
</x-layouts.guest>
