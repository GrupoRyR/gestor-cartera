{{-- Payment commitments of the client. $file, $client. --}}
@php
    $commitmentColumns = [
        ['key' => 'due', 'label' => __('cartera::ficha.compromisos.columnas.vence')],
        ['key' => 'amount', 'label' => __('cartera::ficha.compromisos.columnas.valor'), 'align' => 'end'],
        ['key' => 'status', 'label' => __('cartera::ficha.compromisos.columnas.estado')],
        ['key' => 'created_by', 'label' => __('cartera::ficha.compromisos.columnas.creado_por')],
        ['key' => 'notes', 'label' => __('cartera::ficha.compromisos.columnas.notas')],
    ];
@endphp

<x-ui.card :title="__('cartera::ficha.compromisos.titulo')" :description="__('cartera::ficha.compromisos.descripcion')">
    @if ($file->commitments === [])
        <x-ui.empty icon="calendar-check" :title="__('cartera::ficha.compromisos.vacio.titulo')" :description="__('cartera::ficha.compromisos.vacio.detalle')" />
    @else
        <x-ui.table :caption="__('cartera::ficha.compromisos.tabla')" :caption-hidden="true" :columns="$commitmentColumns">
            @foreach ($file->commitments as $commitment)
                <tr>
                    <td><x-ui.date :value="$commitment->dueDate" /></td>
                    <td data-align="end"><x-ui.money :value="$commitment->amount" /></td>
                    <td><x-cartera::commitment-status :status="$commitment->status" /></td>
                    <td>{{ $commitment->createdBy->name }}</td>
                    <td>{{ $commitment->notes ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-ui.card>
