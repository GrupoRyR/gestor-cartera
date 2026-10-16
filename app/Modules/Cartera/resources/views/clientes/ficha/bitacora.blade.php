{{-- The client's log (gestiones). $file. --}}
<x-ui.card :title="__('cartera::ficha.bitacora.titulo')" :description="__('cartera::ficha.bitacora.descripcion')">
    @forelse ($file->activities as $activity)
        <div class="flex items-start justify-between gap-4 border-b border-line py-3 first:pt-0 last:border-b-0 last:pb-0">
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2">
                    <x-ui.chip :dot="false">{{ $activity->type->label() }}</x-ui.chip>
                    <span class="font-semibold [overflow-wrap:anywhere]">{{ $activity->summary }}</span>
                </p>

                @if ($activity->result)
                    <p class="mt-1 text-sm [overflow-wrap:anywhere]">{{ __('cartera::ficha.bitacora.resultado') }}: {{ $activity->result }}</p>
                @endif

                @if ($activity->details)
                    <p class="mt-1 text-sm whitespace-pre-line text-fg-soft [overflow-wrap:anywhere]">{{ $activity->details }}</p>
                @endif

                <p class="mt-1 text-sm text-fg-muted">
                    <x-ui.date :value="$activity->occurredAt" :time="true" />
                    · {{ $activity->user !== null ? __('cartera::ficha.bitacora.por', ['usuario' => $activity->user->name]) : __('cartera::ficha.bitacora.sistema') }}
                </p>
            </div>

            @can('core.activities.delete')
                <form
                    method="POST"
                    action="{{ route('core.gestiones.destroy', $activity->id) }}"
                    class="shrink-0"
                    x-data
                    x-on:submit="if (! confirm(@js(__('cartera::ficha.bitacora.confirmar_eliminar')))) $event.preventDefault()"
                >
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="ghost" icon="trash">{{ __('cartera::ficha.bitacora.eliminar') }}<span data-visually-hidden> {{ __('cartera::ficha.bitacora.eliminar_de', ['resumen' => $activity->summary]) }}</span></x-ui.button>
                </form>
            @endcan
        </div>
    @empty
        <x-ui.empty icon="history" :title="__('cartera::ficha.bitacora.vacio.titulo')" :description="__('cartera::ficha.bitacora.vacio.detalle')" />
    @endforelse
</x-ui.card>
