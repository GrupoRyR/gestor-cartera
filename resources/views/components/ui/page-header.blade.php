{{--
    Heading of a page (C.5): the page's only <h1>, an optional short context
    line above it, a description and an "actions" slot.
--}}
@props(['title', 'context' => null, 'description' => null])

<header {{ $attributes }}>
    <div data-part="heading">
        @if (filled($context))
            <p data-part="context">{{ $context }}</p>
        @endif

        <h1>{{ $title }}</h1>

        @if (filled($description))
            <p data-part="description">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div data-part="actions">{{ $actions }}</div>
    @endisset
</header>
