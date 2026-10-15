{{-- HTTP 404 page, rendered by Laravel's exception handler. --}}
<x-layouts.guest title="No encontramos esta página">
    <x-ui.page-header title="No encontramos esta página" context="Error 404" />

    <p>Revisa la dirección o vuelve al inicio para buscar lo que necesitas.</p>

    <x-ui.link-button :href="url('/')">Volver al inicio</x-ui.link-button>
</x-layouts.guest>
