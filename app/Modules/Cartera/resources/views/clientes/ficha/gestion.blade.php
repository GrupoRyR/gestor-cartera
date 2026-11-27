@use('App\Modules\Cartera\Domain\Commitments\CommitmentWriting')

{{--
    The collector's buttons and the forms they open: a call (llamada.blade.php,
    posting to cartera.llamadas.store), a note and a discount offered (posting
    to cartera.gestiones.store). Each form nests its fields under its own name;
    a form that fails opens again with what was typed. $file, $detail, $client.
--}}
@can('cartera.manage')
    <x-ui.card :title="__('cartera::gestion.titulo')" :description="__('cartera::gestion.descripcion')">
        <div class="flex flex-wrap gap-2">
            <x-ui.button icon="phone" data-modal-open="gestion-llamada">{{ __('cartera::llamadas.formulario.boton') }}</x-ui.button>
            <x-ui.button variant="secondary" icon="edit" data-modal-open="gestion-nota">{{ __('cartera::gestion.nota.boton') }}</x-ui.button>
            <x-ui.button variant="secondary" icon="money" data-modal-open="gestion-descuento">{{ __('cartera::gestion.descuento.boton') }}</x-ui.button>
            @if (CommitmentWriting::byCollector($client->company) && auth()->user()->can('cartera.commitments.write'))
                <x-ui.button variant="secondary" icon="calendar-check" data-modal-open="compromiso-nuevo">{{ __('cartera::compromisos.nuevo.boton') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>

    @include('cartera::clientes.ficha.llamada')

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
            <x-ui.money-input
                name="descuento[valor]"
                :label="__('cartera::gestion.descuento.valor')"
                :hint="__('cartera::gestion.descuento.valor_ayuda')"
                :required="true"
            />
            <x-ui.textarea name="descuento[detalle]" :label="__('cartera::gestion.descuento.detalle')" :hint="__('cartera::gestion.descuento.detalle_ayuda')" :rows="3" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::gestion.cancelar') }}</x-ui.button>
            <x-ui.button type="submit" form="gestion-descuento-form">{{ __('cartera::gestion.descuento.guardar') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endcan
