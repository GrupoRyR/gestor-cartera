{{-- HTTP 500 page, rendered by Laravel's exception handler. --}}
<x-layouts.guest title="Algo salió mal">
    <x-ui.page-header title="Algo salió mal" context="Error 500" />

    <p>Tuvimos un problema al procesar tu solicitud. Inténtalo de nuevo en unos minutos.</p>

    <x-ui.link-button :href="url('/')">Volver al inicio</x-ui.link-button>
</x-layouts.guest>
