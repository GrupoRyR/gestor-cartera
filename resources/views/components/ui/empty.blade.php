{{-- Empty state (C.5): what is missing and, in the default slot, what to do about it. --}}
@props(['title', 'description' => null, 'icon' => null])

<div {{ $attributes->class('flex flex-col items-center gap-3 rounded-card border border-dashed border-line-strong bg-surface px-6 py-8 text-center md:py-10') }}>
    @if (filled($icon))
        <span class="mb-1 grid size-16 place-items-center rounded-full bg-brand-tint text-brand-ink">
            <x-ui.icon :name="$icon" class="size-8" />
        </span>
    @endif

    <p data-part="title" class="max-w-md text-lg leading-snug font-bold text-balance text-fg">{{ $title }}</p>

    @if (filled($description))
        <p data-part="description" class="max-w-md text-[0.9375rem] text-fg-muted">{{ $description }}</p>
    @endif

    @if ($slot->hasActualContent())
        <div data-part="actions" class="mt-2 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
