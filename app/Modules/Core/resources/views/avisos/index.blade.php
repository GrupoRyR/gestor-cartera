@use('App\Modules\Core\Http\Controllers\NoticeController')

{{--
    GET /avisos (core.avisos.index): the notices of the signed-in user, the
    same items as the bell plus their company. $notices: paginator of
    Notice, newest first, 20 per page; $filter: "todos" or "no-leidos"
    (?filtro=); $unread: the unread, open ones (the bell's badge), which
    decide whether «Marcar todo como leído» shows.
--}}
@php
    $tabs = array_map(fn (string $key): array => [
        'key' => $key,
        'label' => __('core::avisos.filtros.'.$key),
        'href' => route('core.avisos.index', $key === NoticeController::UNREAD_FILTER ? ['filtro' => $key] : []),
    ], ['todos', NoticeController::UNREAD_FILTER]);
@endphp

<x-layouts.app :title="__('core::avisos.titulo')">
    <x-ui.page-header :title="__('core::avisos.titulo')" :description="__('core::avisos.descripcion')">
        @if ($unread > 0)
            <x-slot:actions>
                <form method="POST" action="{{ route('core.avisos.read-all') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="check">{{ __('core::avisos.marcar_todos') }}</x-ui.button>
                </form>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        <x-ui.tabs :label="__('core::avisos.filtros.etiqueta')" :items="$tabs" :current="$filter" />

        @if ($notices->isEmpty())
            <x-ui.empty icon="bell" :title="__('core::avisos.vacio.'.$filter)" />
        @else
            <x-ui.card>
                <ul class="flex flex-col gap-0.5">
                    @foreach ($notices as $notice)
                        <li><x-core::notice-item :notice="$notice" :company="true" /></li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.pagination :paginator="$notices" />
        @endif
    </div>
</x-layouts.app>
