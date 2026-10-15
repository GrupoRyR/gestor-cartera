{{-- HTTP 503 page, rendered by Laravel's exception handler. --}}
<x-layouts.guest title="Estamos actualizando la aplicación">
    <x-ui.page-header title="Estamos actualizando la aplicación" context="Error 503" />

    <p>Vuelve a intentarlo en unos minutos.</p>

    <x-ui.link-button :href="url('/')">Volver al inicio</x-ui.link-button>
</x-layouts.guest>
