{{--
    Native <dialog> (C.5). Any element with data-modal-open="{id}" opens it
    and data-modal-close closes it (resources/js/app.js). With :open="true"
    it opens on page load, e.g. after a validation error.
--}}
@props(['id', 'title', 'open' => false])

<dialog {{ $attributes->merge([
    'id' => $id,
    'aria-labelledby' => "{$id}-title",
    'data-modal-autoopen' => $open ? true : null,
]) }}>
    <header data-part="header">
        <h2 id="{{ $id }}-title">{{ $title }}</h2>
        <x-ui.button variant="ghost" icon="close" :icon-only="true" data-modal-close>Cerrar</x-ui.button>
    </header>

    <div data-part="body">{{ $slot }}</div>

    @isset($footer)
        <footer data-part="footer">{{ $footer }}</footer>
    @endisset
</dialog>
