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
    $hasHeader = $titled || filled($description) || isset($actions);

    $headingClass = match ($level) {
        2 => 'text-lg leading-snug font-bold',
        3 => 'text-base leading-snug font-bold',
        default => 'font-sans text-[0.9375rem] leading-snug font-bold tracking-normal',
    };
@endphp

<{{ $tag }} {{ $attributes->merge(['aria-labelledby' => $headingId])->class('flex min-w-0 flex-col rounded-card border border-line bg-surface') }}>
    @if ($hasHeader)
        <header data-part="header" class="flex items-start justify-between gap-4 px-5 pt-5">
            @if ($titled || filled($description))
                <div class="min-w-0 flex-1">
                    @if ($titled)
                        <h{{ $level }} id="{{ $headingId }}" class="{{ $headingClass }}">{{ $title }}</h{{ $level }}>
                    @endif

                    @if (filled($description))
                        <p data-part="description" class="mt-1 text-sm text-fg-muted">{{ $description }}</p>
                    @endif
                </div>
            @endif

            @isset($actions)
                <div data-part="actions" class="-my-1.5 -me-1.5 flex shrink-0 flex-wrap items-center justify-end gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div data-part="body" @class(['min-w-0 flex-1 px-5 pb-5', 'pt-4' => $hasHeader, 'pt-5' => ! $hasHeader])>{{ $slot }}</div>

    @isset($footer)
        <footer data-part="footer" class="border-t border-line px-5 py-3 text-[0.8125rem] text-fg-muted">{{ $footer }}</footer>
    @endisset
</{{ $tag }}>
