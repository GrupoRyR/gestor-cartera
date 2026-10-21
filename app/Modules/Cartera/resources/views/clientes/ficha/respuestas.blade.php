{{-- E-mail replies and bounces of the client, read through Core's MailboxReader. $file. --}}
<x-ui.card :title="__('cartera::ficha.respuestas.titulo')" :description="__('cartera::ficha.respuestas.descripcion')">
    @if (! $file->repliesAvailable)
        <p class="text-fg-muted">{{ __('cartera::ficha.respuestas.no_disponible') }}</p>
    @else
        @forelse ($file->replies as $mail)
            <div class="border-b border-line py-3 first:pt-0 last:border-b-0 last:pb-0">
                <p class="flex flex-wrap items-center gap-2">
                    @if ($mail->isBounce)
                        <x-ui.chip tone="danger">{{ __('cartera::ficha.respuestas.rebote') }}</x-ui.chip>
                    @endif
                    @unless ($mail->read)
                        <x-ui.chip tone="info">{{ __('cartera::ficha.respuestas.sin_leer') }}</x-ui.chip>
                    @endunless
                    <span class="font-semibold [overflow-wrap:anywhere]">{{ $mail->subject }}</span>
                </p>

                @if ($mail->isBounce && $mail->bounceReason)
                    <p class="mt-1 text-sm [overflow-wrap:anywhere]">{{ __('cartera::ficha.respuestas.rebote_motivo', ['motivo' => $mail->bounceReason]) }}</p>
                @endif

                <p class="mt-1 text-sm text-fg-soft [overflow-wrap:anywhere]">{{ $mail->preview }}</p>

                <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-fg-muted">
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('cartera::ficha.respuestas.de', ['remitente' => $mail->from]) }}</span>
                    <span><x-ui.date :value="$mail->receivedAt" :time="true" /></span>
                    @if ($mail->url && str_starts_with(strtolower($mail->url), 'https://'))
                        <x-ui.link-button :href="$mail->url" variant="ghost" icon="external" :external="true">{{ __('cartera::ficha.respuestas.abrir') }}</x-ui.link-button>
                    @endif
                </p>
            </div>
        @empty
            <x-ui.empty icon="inbox" :title="__('cartera::ficha.respuestas.vacio.titulo')" :description="__('cartera::ficha.respuestas.vacio.detalle')" />
        @endforelse
    @endif
</x-ui.card>
