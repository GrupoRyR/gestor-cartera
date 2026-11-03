{{--
    Native <dialog> (C.5). Any element with data-modal-open="{id}" opens it
    and data-modal-close closes it (resources/js/app.js); Escape and a click
    on the backdrop close it too. With :open="true" it opens on page load,
    e.g. after a validation error. On phones it rises as a bottom sheet.
    Browsers give <dialog> its own text color, so the body sets the brand
    text color and the title the title color.
--}}
@props(['id', 'title', 'open' => false])

<dialog {{ $attributes->merge([
    'id' => $id,
    'aria-labelledby' => "{$id}-title",
    'data-part' => 'modal',
    'data-modal-autoopen' => $open ? true : null,
])->class([
    'm-auto max-h-[calc(100dvh-2rem)] w-[min(34rem,calc(100%-2rem))] flex-col overflow-hidden rounded-card border border-line bg-surface p-0 text-brand-text shadow-pop open:flex',
    'max-sm:mb-0 max-sm:max-h-[calc(100dvh-1rem)] max-sm:w-full max-sm:max-w-full max-sm:rounded-b-none max-sm:border-x-0 max-sm:border-b-0 max-sm:pb-[env(safe-area-inset-bottom)]',
]) }}>
    <header data-part="header" class="flex items-start justify-between gap-4 px-5 pt-4 pb-2">
        <h2 id="{{ $id }}-title" class="pt-2 text-xl leading-snug font-bold text-fg">{{ $title }}</h2>
        <x-ui.button variant="ghost" icon="close" :icon-only="true" data-modal-close class="-me-2">Cerrar</x-ui.button>
    </header>

    <div data-part="body" class="min-h-0 flex-1 overflow-y-auto px-5 pt-2 pb-5 text-[0.9375rem]">{{ $slot }}</div>

    @isset($footer)
        <footer data-part="footer" class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-subtle/50 px-5 py-4 max-sm:*:flex-1">{{ $footer }}</footer>
    @endisset
</dialog>
