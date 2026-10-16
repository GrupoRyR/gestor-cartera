@use('App\Modules\Cartera\Domain\Commitments\CommitmentWriting')
@use('App\Modules\Cartera\Domain\Management\CallResult')

{{--
    The collector's buttons and the forms they open: a call, a note, a
    discount offered. They post to cartera.gestiones.store, each kind with its
    fields nested under its own name; a form that fails opens again with what
    was typed. $file, $client.
--}}
@can('cartera.manage')
    <x-ui.card :title="__('cartera::gestion.titulo')" :description="__('cartera::gestion.descripcion')">
        <div class="flex flex-wrap gap-2">
            <x-ui.button icon="phone" data-modal-open="gestion-llamada">{{ __('cartera::gestion.llamada.boton') }}</x-ui.button>
            <x-ui.button variant="secondary" icon="edit" data-modal-open="gestion-nota">{{ __('cartera::gestion.nota.boton') }}</x-ui.button>
            <x-ui.button variant="secondary" icon="money" data-modal-open="gestion-descuento">{{ __('cartera::gestion.descuento.boton') }}</x-ui.button>
            @if (CommitmentWriting::byCollector($client->company) && auth()->user()->can('cartera.commitments.write'))
                <x-ui.button variant="secondary" icon="calendar-check" data-modal-open="compromiso-nuevo">{{ __('cartera::compromisos.nuevo.boton') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>

    <x-ui.modal id="gestion-llamada" :title="__('cartera::gestion.llamada.titulo')" :open="$errors->any() && old('tipo') === 'llamada'">
        <form id="gestion-llamada-form" method="POST" action="{{ route('cartera.gestiones.store', $client->id) }}" class="grid gap-4">
            @csrf
            <input type="hidden" name="tipo" value="llamada">
            <x-ui.select
                name="llamada[resultado]"
                :label="__('cartera::gestion.llamada.resultado')"
                :options="CallResult::options()"
                :placeholder="__('cartera::gestion.llamada.elige')"
                :required="true"
            />
            <x-ui.textarea name="llamada[detalle]" :label="__('cartera::gestion.llamada.detalle')" :hint="__('cartera::gestion.llamada.detalle_ayuda')" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::gestion.cancelar') }}</x-ui.button>
            <x-ui.button type="submit" form="gestion-llamada-form">{{ __('cartera::gestion.llamada.guardar') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="gestion-nota" :title="__('cartera::gestion.nota.titulo')" :open="$errors->any() && old('tipo') === 'nota'">
        <form id="gestion-nota-form" method="POST" action="{{ route('cartera.gestiones.store', $client->id) }}" class="grid gap-4">
            @csrf
            <input type="hidden" name="tipo" value="nota">
            <x-ui.textarea name="nota[detalle]" :label="__('cartera::gestion.nota.texto')" :hint="__('cartera::gestion.nota.texto_ayuda')" :required="true" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::gestion.cancelar') }}</x-ui.button>
            <x-ui.button type="submit" form="gestion-nota-form">{{ __('cartera::gestion.nota.guardar') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="gestion-descuento" :title="__('cartera::gestion.descuento.titulo')" :open="$errors->any() && old('tipo') === 'descuento'">
        <form id="gestion-descuento-form" method="POST" action="{{ route('cartera.gestiones.store', $client->id) }}" class="grid gap-4">
            @csrf
            <input type="hidden" name="tipo" value="descuento">
            <x-ui.input
                name="descuento[valor]"
                type="number"
                prefix="$"
                :label="__('cartera::gestion.descuento.valor')"
                :hint="__('cartera::gestion.descuento.valor_ayuda')"
                :required="true"
                min="1"
                step="1"
                inputmode="numeric"
            />
            <x-ui.textarea name="descuento[detalle]" :label="__('cartera::gestion.descuento.detalle')" :hint="__('cartera::gestion.descuento.detalle_ayuda')" :rows="3" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::gestion.cancelar') }}</x-ui.button>
            <x-ui.button type="submit" form="gestion-descuento-form">{{ __('cartera::gestion.descuento.guardar') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endcan
