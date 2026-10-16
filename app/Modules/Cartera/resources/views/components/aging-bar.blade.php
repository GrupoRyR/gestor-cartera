{{--
    Stacked bar of the overdue debt by age, with its legend (<x-cartera::aging-bar>).
    One brand hue from light to dark: the older the debt, the darker the
    band. Meaning never rests on the shade alone: every band is listed with
    its name, amount and share, and a 2-pixel gap separates the segments.

    $slices: list<AgingSlice> from AgingSlices::from(); $label names the chart
    for screen readers.
--}}
@props(['slices', 'label'])

@php
    $shades = [
        1 => 'bg-brand-primary/40',
        2 => 'bg-brand-primary/60',
        3 => 'bg-brand-primary/80',
        4 => 'bg-brand-primary',
    ];
@endphp

<div {{ $attributes }}>
    <div role="img" aria-label="{{ $label }}" class="flex h-4 w-full gap-0.5 overflow-hidden rounded-full bg-subtle">
        @foreach ($slices as $slice)
            @if ($slice->share > 0)
                <span class="{{ $shades[$slice->band->severity()] }} h-full" style="width: {{ number_format($slice->share, 2, '.', '') }}%"></span>
            @endif
        @endforeach
    </div>

    <ul class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
        @foreach ($slices as $slice)
            <li class="flex items-center gap-2">
                <span class="{{ $shades[$slice->band->severity()] }} size-3 shrink-0 rounded-sm" aria-hidden="true"></span>
                <span class="text-fg-soft">{{ __('cartera::tablero.edades.bandas.'.$slice->band->value) }}</span>
                <span class="ms-auto text-end font-semibold tabular-nums"><x-ui.money :value="$slice->amount" /></span>
            </li>
        @endforeach
    </ul>
</div>
