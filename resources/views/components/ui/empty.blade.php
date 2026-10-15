{{-- Empty state (C.5): what is missing and, in the default slot, what to do about it. --}}
@props(['title', 'description' => null, 'icon' => null])

<div {{ $attributes }}>
    @if (filled($icon))
        <x-ui.icon :name="$icon" />
    @endif

    <p data-part="title">{{ $title }}</p>

    @if (filled($description))
        <p data-part="description">{{ $description }}</p>
    @endif

    @if ($slot->hasActualContent())
        <div data-part="actions">{{ $slot }}</div>
    @endif
</div>
