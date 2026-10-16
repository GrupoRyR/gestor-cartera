@use('App\Modules\Asesores\Domain\Portfolio\CutoffAge')
@use('App\Support\Formatting\DateFormat')

{{--
    The cutoff date of the snapshot on screen. When it is older than
    config('asesores.stale_after_days') it becomes a warning: the numbers may
    not be today's. Prints nothing without a snapshot (the page shows its
    empty state).
--}}
@props(['cutoff', 'today'])

@if ($cutoff !== null)
    @if (CutoffAge::isStale($cutoff, $today, (int) config('asesores.stale_after_days')))
        <x-ui.alert tone="warning" :title="__('asesores::comun.corte.viejo_titulo')">
            {{ __('asesores::comun.corte.viejo', ['fecha' => DateFormat::date($cutoff), 'dias' => CutoffAge::days($cutoff, $today)]) }}
        </x-ui.alert>
    @else
        <p data-part="cutoff" class="text-sm text-fg-muted">{{ __('asesores::comun.corte.al', ['fecha' => DateFormat::date($cutoff)]) }}</p>
    @endif
@endif
