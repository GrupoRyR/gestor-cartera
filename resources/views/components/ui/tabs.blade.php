{{--
    Navigation tabs (C.5): every tab is a URL, there are no client-side
    panels. Items: ['key' => string, 'label' => string, 'href' => string,
    'count' => ?int]. The tab whose key is current gets aria-current="page".
--}}
@props(['label', 'items', 'current' => null])

<nav {{ $attributes->merge(['aria-label' => $label]) }}>
    <ul>
        @foreach ($items as $item)
            <li>
                <a href="{{ $item['href'] }}" @if ($current !== null && $item['key'] === $current) aria-current="page" @endif>
                    <span>{{ $item['label'] }}</span>
                    @if (isset($item['count']))
                        <span data-part="count">{{ $item['count'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
