{{--
    Navigation tabs (C.5): every tab is a URL, there are no client-side
    panels. Items: ['key' => string, 'label' => string, 'href' => string,
    'count' => ?int]. The tab whose key is current gets aria-current="page".
    On narrow screens the row scrolls sideways.
--}}
@props(['label', 'items', 'current' => null])

<nav {{ $attributes->merge(['aria-label' => $label])->class('min-w-0 border-b border-line') }}>
    <ul class="-mb-px flex gap-1 overflow-x-auto overscroll-x-contain [scrollbar-width:none]">
        @foreach ($items as $item)
            @php($active = $current !== null && $item['key'] === $current)
            <li class="shrink-0">
                <a href="{{ $item['href'] }}" @if ($active) aria-current="page" @endif @class([
                    'relative flex min-h-touch items-center gap-2 rounded-t-[10px] px-3 text-[0.9375rem] font-semibold whitespace-nowrap no-underline transition-colors',
                    'after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full',
                    'text-brand-ink after:bg-brand-ink' => $active,
                    'text-fg-soft hover:bg-subtle hover:text-fg' => ! $active,
                ])>
                    <span>{{ $item['label'] }}</span>
                    @if (isset($item['count']))
                        <span data-part="count" @class([
                            'min-w-6 rounded-full px-2 py-0.5 text-center text-xs font-bold tabular-nums',
                            'bg-brand-tint text-brand-ink' => $active,
                            'bg-subtle text-fg-soft' => ! $active,
                        ])>{{ $item['count'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
