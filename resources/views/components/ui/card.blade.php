@use('Illuminate\Support\Str')

{{--
    Content block (C.5). With a title it is a <section> named by its heading;
    without one, a plain <div>. Slots: default (body), "actions", "footer".
--}}
@props(['title' => null, 'description' => null, 'level' => 2])

@php
    $level = (int) $level;

    if ($level < 2 || $level > 4) {
        throw new InvalidArgumentException("The card heading level must be 2, 3 or 4, [{$level}] given.");
    }

    $titled = filled($title);
    $tag = $titled ? 'section' : 'div';
    $headingId = $titled ? $attributes->get('id', 'card-'.Str::lower(Str::random(8))).'-title' : null;
@endphp

<{{ $tag }} {{ $attributes->merge(['aria-labelledby' => $headingId]) }}>
    @if ($titled || filled($description) || isset($actions))
        <header data-part="header">
            @if ($titled)
                <h{{ $level }} id="{{ $headingId }}">{{ $title }}</h{{ $level }}>
            @endif

            @if (filled($description))
                <p data-part="description">{{ $description }}</p>
            @endif

            @isset($actions)
                <div data-part="actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div data-part="body">{{ $slot }}</div>

    @isset($footer)
        <footer data-part="footer">{{ $footer }}</footer>
    @endisset
</{{ $tag }}>
