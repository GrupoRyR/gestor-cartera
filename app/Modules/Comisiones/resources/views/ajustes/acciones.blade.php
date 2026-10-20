@use('App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus')
@use('App\Modules\Comisiones\Infrastructure\Settlement\ApprovalGuard')

{{--
    The actions on a capture row, only while it is pending and its period
    is open: approve or reject (comisiones.approve, never its creator,
    §10) and delete (comisiones.capture, only its creator). Rejecting asks
    for the reason in a dialog, reopened after a failed submit for the row
    named by old('captura'). $capture; $kind: CaptureKind; $locked.
--}}
@php
    $me = auth()->user();
    $open = $capture->estado === CaptureStatus::Pending && ! $locked && $me !== null;
    $canApprove = $open && $me->can('comisiones.approve');
    $mayReview = $canApprove && app(ApprovalGuard::class)->mayReview($capture, $me);
    $canDelete = $open && $me->can('comisiones.capture') && $capture->created_by === $me->id;
    $route = ['tipo' => $kind->value, 'captura' => $capture->id];
    $dialog = "rechazar-{$kind->value}-{$capture->id}";
@endphp

@if ($mayReview || $canDelete || $canApprove)
    <div class="flex flex-wrap items-center gap-2">
        @if ($mayReview)
            <form method="POST" action="{{ route('comisiones.ajustes.aprobar', $route) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="check" :aria-label="__('comisiones::ajustes.revision.aprobar_de', ['id' => $capture->id])">{{ __('comisiones::ajustes.revision.aprobar') }}</x-ui.button>
            </form>
            <x-ui.button variant="ghost" icon="close" :data-modal-open="$dialog" :aria-label="__('comisiones::ajustes.revision.rechazar_de', ['id' => $capture->id])">{{ __('comisiones::ajustes.revision.rechazar') }}</x-ui.button>
            <x-ui.modal :id="$dialog" :title="__('comisiones::ajustes.revision.rechazar_titulo', ['id' => $capture->id])" :open="$errors->has('motivo_rechazo') && (int) old('captura') === $capture->id">
                <form id="form-{{ $dialog }}" method="POST" action="{{ route('comisiones.ajustes.rechazar', $route) }}" class="flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="captura" value="{{ $capture->id }}">
                    <p class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::ajustes.revision.rechazar_ayuda') }}</p>
                    <x-ui.textarea name="motivo_rechazo" :id="'motivo-'.$dialog" :label="__('comisiones::ajustes.revision.motivo')" :rows="3" :required="true" maxlength="500" />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="ghost" data-modal-close>{{ __('comisiones::ajustes.revision.cancelar') }}</x-ui.button>
                    <x-ui.button type="submit" variant="danger" :form="'form-'.$dialog">{{ __('comisiones::ajustes.revision.rechazar') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @elseif ($canApprove)
            <span class="text-sm text-fg-muted">{{ __('comisiones::ajustes.revision.propio') }}</span>
        @endif
        @if ($canDelete)
            <form method="POST" action="{{ route('comisiones.ajustes.destroy', $route) }}">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="ghost" icon="trash" :icon-only="true">{{ __('comisiones::ajustes.revision.eliminar', ['id' => $capture->id]) }}</x-ui.button>
            </form>
        @endif
    </div>
@else
    {{ __('comisiones::comun.sin_dato') }}
@endif
