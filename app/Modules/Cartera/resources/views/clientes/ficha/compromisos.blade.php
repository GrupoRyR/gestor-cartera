@use('App\Modules\Cartera\Application\Commitments\SuggestedAmount')
@use('App\Modules\Cartera\Domain\Commitments\CommitmentWriting')
@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')
@use('App\Support\Formatting\Money')

{{--
    Payment commitments of the client. In SDC the analyst creates them and
    edits or cancels the open ones; in SGX the advisor writes them. In both
    companies the analyst and the manager mark them fulfilled or broken
    (cartera.commitments.status). A partially paid one shows what was paid
    and what is left. The balance notice links here with
    ?nuevo-compromiso=1&valor=…: the "Nuevo compromiso" form opens with that
    value for whoever may write. $file, $client.
--}}
@php
    $collectorWrites = CommitmentWriting::byCollector($client->company);
    $canWrite = $collectorWrites && auth()->user()?->can('cartera.commitments.write');
    $canChangeStatus = auth()->user()?->can('cartera.commitments.status') ?? false;
    $suggestedAmount = SuggestedAmount::from(request()->query('valor'));

    $commitmentColumns = [
        ['key' => 'due', 'label' => __('cartera::ficha.compromisos.columnas.vence')],
        ['key' => 'amount', 'label' => __('cartera::ficha.compromisos.columnas.valor'), 'align' => 'end'],
        ['key' => 'status', 'label' => __('cartera::ficha.compromisos.columnas.estado')],
        ['key' => 'created_by', 'label' => __('cartera::ficha.compromisos.columnas.creado_por')],
        ['key' => 'notes', 'label' => __('cartera::ficha.compromisos.columnas.notas')],
        ...($canWrite || $canChangeStatus ? [['key' => 'actions', 'label' => __('cartera::compromisos.acciones'), 'align' => 'end']] : []),
    ];
@endphp

<x-ui.card :title="__('cartera::ficha.compromisos.titulo')" :description="__('cartera::ficha.compromisos.descripcion')">
    @unless ($collectorWrites)
        <p class="mb-4 text-sm text-fg-muted">{{ __($canChangeStatus ? 'cartera::ficha.compromisos.solo_estado_sgx' : 'cartera::ficha.compromisos.solo_lectura_sgx') }}</p>
    @endunless

    @if ($file->commitments === [])
        <x-ui.empty icon="calendar-check" :title="__('cartera::ficha.compromisos.vacio.titulo')" :description="__('cartera::ficha.compromisos.vacio.detalle')" />
    @else
        <x-ui.table :caption="__('cartera::ficha.compromisos.tabla')" :caption-hidden="true" :columns="$commitmentColumns">
            @foreach ($file->commitments as $commitment)
                @php
                    $isOpen = in_array($commitment->status, [CommitmentStatus::Pending, CommitmentStatus::Broken], true);
                    $about = ['cliente' => $client->name, 'valor' => Money::format($commitment->amount)];
                @endphp
                <tr>
                    <td><x-ui.date :value="$commitment->dueDate" /></td>
                    <td data-align="end">
                        <x-ui.money :value="$commitment->amount" />
                        @if ($commitment->remaining() !== null)
                            <div class="text-sm text-fg-muted">{{ __('cartera::compromisos.pagado', ['pagado' => Money::format($commitment->paidAmount), 'saldo' => Money::format($commitment->remaining())]) }}</div>
                        @endif
                    </td>
                    <td><x-cartera::commitment-status :status="$commitment->status" /></td>
                    <td>{{ $commitment->createdBy->name }}</td>
                    <td>{{ $commitment->notes ?? '—' }}</td>
                    @if ($canWrite || $canChangeStatus)
                        <td data-align="end">
                            @if ($isOpen)
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($canChangeStatus)
                                        <x-cartera::commitment-status-actions :commitment="$commitment" :client-name="$client->name" />
                                    @endif
                                    @if ($canWrite && $commitment->status === CommitmentStatus::Pending)
                                        <x-ui.button variant="ghost" icon="edit" data-modal-open="compromiso-{{ $commitment->id }}-editar">{{ __('cartera::compromisos.editar.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.editar.de', $about) }}</span></x-ui.button>
                                    @endif
                                    @if ($canWrite)
                                        <x-ui.button variant="ghost" icon="close" data-modal-open="compromiso-{{ $commitment->id }}-anular">{{ __('cartera::compromisos.anular.boton') }}<span data-visually-hidden> {{ __('cartera::compromisos.anular.de', $about) }}</span></x-ui.button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-ui.card>

@if ($canWrite)
    @foreach ($file->commitments as $commitment)
        @continue(! in_array($commitment->status, [CommitmentStatus::Pending, CommitmentStatus::Broken], true))
        @php
            $about = ['cliente' => $client->name, 'valor' => Money::format($commitment->amount)];
            $edit = "edicion_{$commitment->id}";
            $cancel = "anulacion_{$commitment->id}";
        @endphp

        @if ($commitment->status === CommitmentStatus::Pending)
            <x-ui.modal id="compromiso-{{ $commitment->id }}-editar" :title="__('cartera::compromisos.editar.titulo')" :open="$errors->has($edit.'.*') || old('form') === $edit">
                <form id="compromiso-{{ $commitment->id }}-editar-form" method="POST" action="{{ route('cartera.compromisos.update', $commitment->id) }}" class="grid gap-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="form" value="{{ $edit }}">
                    <p class="text-fg-muted">{{ __('cartera::compromisos.editar.ayuda') }}</p>
                    <x-ui.input :name="$edit.'[fecha]'" type="date" :label="__('cartera::compromisos.editar.fecha')" :value="$commitment->dueDate->toDateString()" />
                    <x-ui.input :name="$edit.'[valor]'" type="number" prefix="$" :label="__('cartera::compromisos.editar.valor')" :value="(int) $commitment->amount" min="1" step="1" inputmode="numeric" />
                    <x-ui.textarea :name="$edit.'[notas]'" :label="__('cartera::compromisos.editar.notas')" :value="$commitment->notes" :rows="3" />
                </form>

                <x-slot:footer>
                    <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::compromisos.cancelar') }}</x-ui.button>
                    <x-ui.button type="submit" form="compromiso-{{ $commitment->id }}-editar-form">{{ __('cartera::compromisos.editar.guardar') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif

        <x-ui.modal id="compromiso-{{ $commitment->id }}-anular" :title="__('cartera::compromisos.anular.titulo')" :open="$errors->has($cancel.'.*') || old('form') === $cancel">
            <form id="compromiso-{{ $commitment->id }}-anular-form" method="POST" action="{{ route('cartera.compromisos.anular', $commitment->id) }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="form" value="{{ $cancel }}">
                <p>{{ __('cartera::compromisos.anular.pregunta', $about) }}</p>
                <x-ui.textarea :name="$cancel.'[motivo]'" :label="__('cartera::compromisos.anular.motivo')" :hint="__('cartera::compromisos.anular.motivo_ayuda')" :rows="3" :required="true" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::compromisos.cancelar') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger" form="compromiso-{{ $commitment->id }}-anular-form">{{ __('cartera::compromisos.anular.guardar') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach

    <x-ui.modal id="compromiso-nuevo" :title="__('cartera::compromisos.nuevo.titulo')" :open="$errors->has('compromiso.*') || request()->boolean('nuevo-compromiso')">
        <form id="compromiso-nuevo-form" method="POST" action="{{ route('cartera.compromisos.store', $client->id) }}" class="grid gap-4">
            @csrf
            <x-ui.input name="compromiso[fecha]" type="date" :label="__('cartera::compromisos.nuevo.fecha')" :hint="__('cartera::compromisos.nuevo.fecha_ayuda')" :required="true" :min="now()->toDateString()" />
            <x-ui.input name="compromiso[valor]" type="number" prefix="$" :label="__('cartera::compromisos.nuevo.valor')" :hint="__('cartera::compromisos.nuevo.valor_ayuda')" :value="$suggestedAmount" :required="true" min="1" step="1" inputmode="numeric" />
            <x-ui.textarea name="compromiso[notas]" :label="__('cartera::compromisos.nuevo.notas')" :hint="__('cartera::compromisos.nuevo.notas_ayuda')" :rows="3" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::compromisos.cancelar') }}</x-ui.button>
            <x-ui.button type="submit" form="compromiso-nuevo-form">{{ __('cartera::compromisos.nuevo.guardar') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif
