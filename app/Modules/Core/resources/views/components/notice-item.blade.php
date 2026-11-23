{{--
    One notice of the signed-in user, as the bell and the /avisos page list
    it: a link to core.avisos.open, which marks it read and follows its link.
    data-state is "resolved" when it is done (muted, with a «Hecho» chip),
    else "unread" (a dot and stronger text) or "read". The badge counts the
    unread, open ones, so a resolved notice never looks unread.
    $company shows the company chip of the notice, if it has one.
--}}
@props(['notice', 'company' => false])

@php
    $state = $notice->isResolved() ? 'resolved' : ($notice->isRead() ? 'read' : 'unread');
@endphp

<a
    href="{{ route('core.avisos.open', $notice->id) }}"
    data-notice="{{ $notice->id }}"
    data-state="{{ $state }}"
    @class([
        'flex min-h-touch items-start gap-3 rounded-[10px] px-3 py-2 text-sm leading-snug no-underline transition-colors hover:bg-subtle',
        'text-fg-muted' => $state === 'resolved',
    ])
>
    <span aria-hidden="true" @class(['mt-1.5 size-1.5 shrink-0 rounded-full', 'bg-brand-accent' => $state === 'unread'])></span>
    <span class="min-w-0 flex-1">
        @if ($state === 'unread')
            <span data-visually-hidden>{{ __('core::avisos.sin_leer') }}:</span>
        @endif
        <span data-part="title" @class(['block [overflow-wrap:anywhere]', 'font-bold text-fg' => $state === 'unread', 'font-semibold' => $state !== 'unread'])>{{ $notice->title }}</span>
        @if (filled($notice->body))
            <span data-part="body" class="block truncate text-fg-muted">{{ $notice->body }}</span>
        @endif
        <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-fg-muted">
            <time datetime="{{ $notice->createdAt->toIso8601String() }}">{{ $notice->createdAt->locale(app()->getLocale())->diffForHumans() }}</time>
            @if ($company && $notice->company !== null)
                <x-ui.chip :dot="false">{{ $notice->company }}</x-ui.chip>
            @endif
            @if ($state === 'resolved')
                <x-ui.chip tone="success">{{ __('core::avisos.hecho') }}</x-ui.chip>
            @endif
        </span>
    </span>
</a>
