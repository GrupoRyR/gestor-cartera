{{--
    Heading of a page (C.5): the page's only <h1>, an optional short context
    line above it, a description and an "actions" slot. On phones the
    actions drop below the heading; they never grow wider than the page,
    even with large text (each action may shrink and wrap).
--}}
@props(['title', 'context' => null, 'description' => null])

<header {{ $attributes->class('flex flex-wrap items-end justify-between gap-x-6 gap-y-4 pb-6 md:pb-8') }}>
    <div data-part="heading" class="min-w-0 max-w-3xl flex-[1_1_20rem]">
        @if (filled($context))
            <p data-part="context" class="mb-1.5 text-sm font-semibold text-brand-ink">{{ $context }}</p>
        @endif

        <h1 class="text-[1.75rem] leading-[1.08] font-bold tracking-[-0.025em] text-balance text-fg md:text-[2.125rem] lg:text-[2.5rem]">{{ $title }}</h1>

        @if (filled($description))
            <p data-part="description" class="mt-2 max-w-prose text-[0.9375rem] text-fg-muted md:text-base">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div data-part="actions" class="flex max-w-full min-w-0 flex-wrap items-center gap-2 *:min-w-0 max-sm:w-full max-sm:*:grow">{{ $actions }}</div>
    @endisset
</header>
