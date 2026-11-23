@use('App\Modules\Core\Infrastructure\Notices\EloquentAvisoService')

{{--
    The notice bell of the layout header (docs/arquitectura.md §6.18), for
    the signed-in user: a badge with the unread, open notices («9+» above
    9) and a panel with the 10 newest. It copies the account menu: a
    <details> that closes on an outside click or Escape.

    Draws nothing without a saved user: guests, and the unsaved user of the
    component gallery.
--}}
@php
    $userId = auth()->id();
@endphp

@if (is_int($userId))
    @php
        $inbox = app(EloquentAvisoService::class);
        $unread = $inbox->unreadCount($userId);
        $notices = $inbox->latest($userId);
    @endphp

    <details
        data-part="notices"
        class="shrink-0 sm:relative"
        x-data
        x-on:click.outside="$el.open = false"
        x-on:keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()"
    >
        <summary
            aria-label="{{ $unread > 0 ? __('core::avisos.campana_sin_leer', ['count' => $unread]) : __('core::avisos.campana') }}"
            class="relative flex size-touch cursor-pointer items-center justify-center rounded-full select-none hover:bg-brand-switch-track focus-visible:outline-brand-head-fg"
        >
            <x-ui.icon name="bell" />
            @if ($unread > 0)
                <span data-part="badge" aria-hidden="true" class="absolute top-1 start-[calc(50%+0.5rem)] min-w-5 rounded-full bg-brand-button px-1 text-center text-[0.6875rem] leading-[1.125rem] font-bold text-brand-on-button tabular-nums">{{ $unread > 9 ? '9+' : $unread }}</span>
            @endif
        </summary>

        {{-- A light surface like the account panel: it sets its own text color instead of the header's white. --}}
        <div class="absolute inset-x-3 top-full z-40 mt-1 flex max-h-[calc(100dvh-2rem)] flex-col rounded-card border border-line bg-surface p-2 text-brand-text shadow-pop sm:inset-x-auto sm:end-0 sm:mt-2 sm:w-72">
            <div class="flex flex-wrap items-center justify-between gap-2 px-3 pt-2 pb-2">
                <p class="font-bold text-fg">{{ __('core::avisos.titulo') }}</p>
                @if ($unread > 0)
                    <form method="POST" action="{{ route('core.avisos.read-all') }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" icon="check" class="px-3">{{ __('core::avisos.marcar_todos') }}</x-ui.button>
                    </form>
                @endif
            </div>

            @if ($notices === [])
                <p data-part="empty" class="border-t border-line px-3 py-4 text-sm text-fg-muted">{{ __('core::avisos.vacio.todos') }}</p>
            @else
                <ul aria-label="{{ __('core::avisos.lista') }}" class="flex min-h-0 flex-col gap-0.5 overflow-y-auto border-t border-line pt-2">
                    @foreach ($notices as $notice)
                        <li><x-core::notice-item :notice="$notice" /></li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-2 border-t border-line pt-2">
                <x-ui.link-button :href="route('core.avisos.index')" variant="ghost" class="w-full justify-start px-3">{{ __('core::avisos.ver_todos') }}</x-ui.link-button>
            </div>
        </div>
    </details>
@endif
