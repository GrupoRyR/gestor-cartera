@use('App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus')
@use('App\Support\Formatting\DateFormat')

{{--
    The cells every capture row ends with: its review state, who registered
    and who reviewed it, its support and the actions on it. $capture: the
    capture model; $kind: CaptureKind; $period; $locked.
--}}
@php
    $tone = match ($capture->estado) {
        CaptureStatus::Pending => 'warning',
        CaptureStatus::Approved => 'success',
        CaptureStatus::Rejected => 'danger',
    };
@endphp

<td><x-ui.chip :tone="$tone">{{ __('comisiones::ajustes.estados.'.$capture->estado->value) }}</x-ui.chip></td>
<td class="min-w-40 text-sm">
    {{ __('comisiones::ajustes.lista.por', ['fecha' => DateFormat::dateTime($capture->created_at), 'nombre' => $capture->creator->name ?? __('comisiones::comun.sin_dato')]) }}
</td>
<td class="min-w-40 text-sm">
    @if ($capture->revisado_en === null)
        {{ __('comisiones::ajustes.lista.sin_revisar') }}
    @else
        {{ __('comisiones::ajustes.lista.por', ['fecha' => DateFormat::dateTime($capture->revisado_en), 'nombre' => $capture->reviewer->name ?? __('comisiones::comun.sin_dato')]) }}
        @if ($capture->motivo_rechazo !== null)
            <span class="block [overflow-wrap:anywhere]">{{ __('comisiones::ajustes.lista.motivo_rechazo', ['motivo' => $capture->motivo_rechazo]) }}</span>
        @endif
    @endif
</td>
<td>
    @if ($capture->soporte === null)
        {{ __('comisiones::ajustes.lista.sin_soporte') }}
    @else
        <a href="{{ route('comisiones.ajustes.soporte', ['tipo' => $kind->value, 'captura' => $capture->id]) }}"
            class="inline-flex min-h-touch items-center gap-1.5 text-sm font-semibold text-brand-ink underline-offset-4 hover:underline"
            aria-label="{{ __('comisiones::ajustes.lista.ver_soporte_de', ['id' => $capture->id]) }}">
            <x-ui.icon name="download" class="size-4" />{{ __('comisiones::ajustes.lista.ver_soporte') }}
        </a>
    @endif
</td>
<td>
    @include('comisiones::ajustes.acciones', ['capture' => $capture, 'kind' => $kind, 'period' => $period, 'locked' => $locked])
</td>
