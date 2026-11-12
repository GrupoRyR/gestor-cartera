@use('App\Modules\Asesores\Domain\Portfolio\CutoffAge')
@use('App\Support\Formatting\DateFormat')

{{--
    The cutoff date of the snapshot on screen. When it is older than
    config('asesores.stale_after_days') it becomes a warning: the numbers may
    not be today's. Prints nothing without a snapshot (the page shows its
    empty state).

    $updatedAt (optional, from Application\Portfolio\DataFreshness): when
    Ingesta received the cartera batch; shown in Bogotá time after the
    cutoff sentence and inside the warning. Without it nothing more is said.
--}}
@props(['cutoff', 'today', 'updatedAt' => null])

@php
    $updated = $updatedAt === null ? null : __(
        DateFormat::date($updatedAt) === DateFormat::date($today) ? 'asesores::comun.corte.actualizado_hoy' : 'asesores::comun.corte.actualizado',
        ['fecha' => DateFormat::date($updatedAt), 'hora' => $updatedAt->setTimezone(config('app.timezone'))->format('H:i')],
    );
@endphp

@if ($cutoff !== null)
    @if (CutoffAge::isStale($cutoff, $today, (int) config('asesores.stale_after_days')))
        <x-ui.alert tone="warning" :title="__('asesores::comun.corte.viejo_titulo')">
            {{ __('asesores::comun.corte.viejo', ['fecha' => DateFormat::date($cutoff), 'dias' => CutoffAge::days($cutoff, $today)]) }}
            @if ($updated !== null)
                <span data-part="updated-at">{{ $updated }}</span>
            @endif
        </x-ui.alert>
    @else
        <p data-part="cutoff" class="text-sm text-fg-muted">
            {{ __('asesores::comun.corte.al', ['fecha' => DateFormat::date($cutoff)]) }}
            @if ($updated !== null)
                <span data-part="updated-at">{{ $updated }}</span>
            @endif
        </p>
    @endif
@endif
