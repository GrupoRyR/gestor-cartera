@use('App\Support\Formatting\DateFormat')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /cobro/respuestas/{message} (cobro.respuestas.show): one reply or
    bounce with its text, the client and the mail it answers.
    $message: InboundMessage with sentMessage and reader.
--}}
@php($title = $message->subject !== '' ? $message->subject : __('cobro::respuestas.sin_asunto'))

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('cobro::respuestas.detalle.contexto')">
        <x-slot:actions>
            <x-ui.link-button :href="route('cobro.respuestas.index')" variant="ghost" icon="chevron-left">{{ __('cobro::respuestas.acciones.volver') }}</x-ui.link-button>
            <form method="POST" action="{{ route('cobro.respuestas.mark') }}">
                @csrf
                <input type="hidden" name="mensajes[]" value="{{ $message->id }}">
                @if ($message->read_at === null)
                    <x-ui.button type="submit" name="accion" value="leida" icon="check">{{ __('cobro::respuestas.acciones.marcar_leida') }}</x-ui.button>
                @else
                    <x-ui.button type="submit" name="accion" value="pendiente" variant="secondary" icon="inbox">{{ __('cobro::respuestas.acciones.marcar_pendiente') }}</x-ui.button>
                @endif
            </form>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap gap-2">
            @if ($message->is_bounce)
                <x-ui.chip tone="danger">{{ __('cobro::respuestas.rebote') }}</x-ui.chip>
            @endif
            @if ($message->read_at === null)
                <x-ui.chip tone="info">{{ __('cobro::respuestas.pendiente') }}</x-ui.chip>
            @else
                <x-ui.chip tone="neutral" :dot="false">{{ __('cobro::respuestas.detalle.leida_por', ['nombre' => $message->reader?->name ?? '—', 'fecha' => DateFormat::dateTime($message->read_at)]) }}</x-ui.chip>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat :label="__('cobro::respuestas.detalle.de')" :value="$message->from_address" :hint="$message->from_name" />
            <x-ui.stat :label="__('cobro::respuestas.detalle.cliente')" :value="$message->client_name ?? __('cobro::respuestas.sin_cliente')"
                :href="$message->client_id !== null && Route::has('cartera.clientes.show') ? route('cartera.clientes.show', $message->client_id) : null" />
            <x-ui.stat :label="__('cobro::respuestas.detalle.recibido')" :value="DateFormat::dateTime($message->received_at)" />
            @if ($message->sentMessage !== null)
                <x-ui.stat :label="__('cobro::respuestas.detalle.responde_a')" :value="__('cobro::respuestas.detalle.responde_a_detalle', ['asunto' => $message->sentMessage->subject, 'fecha' => DateFormat::date($message->sentMessage->sent_at)])" />
            @endif
        </div>

        @if ($message->is_bounce)
            <x-ui.alert tone="danger" :title="__('cobro::respuestas.detalle.motivo_rebote')">
                <p class="[overflow-wrap:anywhere]">{{ $message->bounce_reason ?? '—' }}</p>
                @if ($message->bounced_recipient !== null)
                    <p>{{ __('cobro::respuestas.detalle.correo_rebotado') }}: {{ $message->bounced_recipient }}</p>
                @endif
            </x-ui.alert>
        @endif

        <x-ui.card :title="__('cobro::respuestas.detalle.texto')">
            @if (filled($message->body))
                <div class="whitespace-pre-wrap [overflow-wrap:anywhere]">{{ $message->body }}</div>
            @elseif (filled($message->preview))
                <p class="[overflow-wrap:anywhere]">{{ $message->preview }}</p>
            @else
                <p>{{ __('cobro::respuestas.detalle.sin_texto') }}</p>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
