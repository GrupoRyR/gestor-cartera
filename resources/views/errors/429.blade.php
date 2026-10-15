{{-- HTTP 429 page, rendered by Laravel's exception handler. --}}
<x-layouts.guest title="Demasiadas solicitudes">
    <x-ui.page-header title="Demasiadas solicitudes" context="Error 429" />

    <p>Espera un momento antes de volver a intentarlo.</p>

    <x-ui.link-button :href="url('/')">Volver al inicio</x-ui.link-button>
</x-layouts.guest>
