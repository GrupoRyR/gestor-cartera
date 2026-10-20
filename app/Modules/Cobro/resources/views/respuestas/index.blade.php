@use('Illuminate\Support\Facades\Route')
@use('Illuminate\Support\Str')

{{--
    GET /cobro/respuestas (cobro.respuestas.index): replies and bounces of
    the user's companies. $messages: paginator of InboundMessage, newest
    first, filtered by $filter (pendientes, leidas or todas); $pending:
    unread messages of the user's companies; $isGroup: shows the company.
--}}
@php
    $tabs = array_map(fn (string $key) => [
        'key' => $key,
        'label' => __('cobro::respuestas.filtros.'.$key),
        'href' => route('cobro.respuestas.index', ['estado' => $key]),
        'count' => $key === 'pendientes' ? $pending : null,
    ], ['pendientes', 'leidas', 'todas']);
    $columns = [
        ['key' => 'message', 'label' => __('cobro::respuestas.columnas.mensaje')],
        ['key' => 'client', 'label' => __('cobro::respuestas.columnas.cliente')],
        ...($isGroup ? [['key' => 'company', 'label' => __('cobro::respuestas.columnas.empresa')]] : []),
        ['key' => 'received', 'label' => __('cobro::respuestas.columnas.recibido')],
        ['key' => 'state', 'label' => __('cobro::respuestas.columnas.estado')],
    ];
    $empty = $filter === 'pendientes' ? 'pendientes' : 'otros';
@endphp

<x-layouts.app :title="__('cobro::respuestas.titulo')">
    <x-ui.page-header :title="__('cobro::respuestas.titulo')" :description="__('cobro::respuestas.descripcion')" />

    <div class="flex flex-col gap-6">
        <x-ui.tabs :label="__('cobro::respuestas.filtros.etiqueta')" :items="$tabs" :current="$filter" />

        @if ($messages->isEmpty())
            <x-ui.empty icon="inbox" :title="__('cobro::respuestas.vacio.'.$empty.'.titulo')" :description="__('cobro::respuestas.vacio.'.$empty.'.detalle')" />
        @else
            <form method="POST" action="{{ route('cobro.respuestas.mark') }}" class="flex flex-col gap-4">
                @csrf

                <x-ui.table :caption="__('cobro::respuestas.tabla')" :caption-hidden="true" :columns="$columns">
                    @foreach ($messages as $message)
                        <tr data-message="{{ $message->id }}">
                            <td class="min-w-56">
                                <x-ui.checkbox name="mensajes[]" :value="$message->id" :label="$message->from_name ?? $message->from_address ?? '—'" />
                                <a href="{{ route('cobro.respuestas.show', $message) }}" class="mt-1 block font-semibold underline underline-offset-2 [overflow-wrap:anywhere]">{{ $message->subject !== '' ? $message->subject : __('cobro::respuestas.sin_asunto') }}</a>
                                <span class="mt-1 block text-sm [overflow-wrap:anywhere]">{{ Str::limit($message->is_bounce ? ($message->bounce_reason ?? $message->preview) : $message->preview, 160) }}</span>
                            </td>
                            <td>
                                @if ($message->client_id === null)
                                    {{ __('cobro::respuestas.sin_cliente') }}
                                @elseif (Route::has('cartera.clientes.show'))
                                    <a href="{{ route('cartera.clientes.show', $message->client_id) }}" class="underline underline-offset-2">{{ $message->client_name ?? $message->client_id }}</a>
                                @else
                                    {{ $message->client_name ?? $message->client_id }}
                                @endif
                            </td>
                            @if ($isGroup)
                                <td><x-ui.chip :dot="false">{{ $message->company->value }}</x-ui.chip></td>
                            @endif
                            <td><x-ui.date :value="$message->received_at" :time="true" /></td>
                            <td>
                                <span class="flex flex-wrap gap-1">
                                    @if ($message->is_bounce)
                                        <x-ui.chip tone="danger">{{ __('cobro::respuestas.rebote') }}</x-ui.chip>
                                    @endif
                                    @if ($message->read_at === null)
                                        <x-ui.chip tone="info">{{ __('cobro::respuestas.pendiente') }}</x-ui.chip>
                                    @else
                                        <x-ui.chip tone="neutral" :dot="false">{{ __('cobro::respuestas.leida') }}</x-ui.chip>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$messages" />

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" name="accion" value="leida" icon="check">{{ __('cobro::respuestas.acciones.marcar_leidas') }}</x-ui.button>
                    <x-ui.button type="submit" name="accion" value="pendiente" variant="secondary" icon="inbox">{{ __('cobro::respuestas.acciones.marcar_pendientes') }}</x-ui.button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
