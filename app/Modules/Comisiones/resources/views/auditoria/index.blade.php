@use('App\Modules\Comisiones\Infrastructure\Audit\AuditLabels')
@use('App\Modules\Comisiones\Infrastructure\Audit\AuditTrail')

{{--
    GET /comisiones/auditoria (comisiones.auditoria.index): the audit trail
    (§7.9), read only. $entries: LengthAwarePaginator of AuditRecord, most
    recent first; $filters: AuditFilters; $users, $events, $periods: the
    options of the filters, value => label, taken from the trail itself.
    Each row discloses its changes (old → new) and context in <details>.
--}}
@php
    $title = __('comisiones::auditoria.titulo');
    $columns = [];

    foreach (['fecha', 'usuario', 'evento', 'periodo', 'sujeto', 'motivo', 'detalle'] as $column) {
        $columns[] = ['key' => $column, 'label' => __('comisiones::auditoria.columnas.'.$column)];
    }
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :context="__('comisiones::comun.modulo')" :description="__('comisiones::auditoria.descripcion')" />

    <div class="flex flex-col gap-6">
        <x-ui.card :title="__('comisiones::auditoria.filtros.titulo')">
            <form method="GET" action="{{ route('comisiones.auditoria.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-[repeat(auto-fill,minmax(min(8rem,100%),1fr))] sm:items-end">
                <x-ui.select name="usuario" :label="__('comisiones::auditoria.filtros.usuario')" :options="$users" :value="$filters->user" :placeholder="__('comisiones::auditoria.filtros.todos')" />
                <x-ui.input name="desde" type="date" :label="__('comisiones::auditoria.filtros.desde')" :value="$filters->from?->format('Y-m-d')" />
                <x-ui.input name="hasta" type="date" :label="__('comisiones::auditoria.filtros.hasta')" :value="$filters->to?->format('Y-m-d')" />
                <x-ui.select name="evento" :label="__('comisiones::auditoria.filtros.evento')" :options="$events" :value="$filters->event" :placeholder="__('comisiones::auditoria.filtros.todos')" />
                <x-ui.select name="periodo" :label="__('comisiones::auditoria.filtros.periodo')" :options="$periods" :value="$filters->period" :placeholder="__('comisiones::auditoria.filtros.todos')" />
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" icon="filter">{{ __('comisiones::auditoria.filtros.aplicar') }}</x-ui.button>
                    @unless ($filters->isEmpty())
                        <x-ui.link-button :href="route('comisiones.auditoria.index')" variant="ghost">{{ __('comisiones::auditoria.filtros.limpiar') }}</x-ui.link-button>
                    @endunless
                </div>
            </form>
        </x-ui.card>

        <x-ui.card :title="trans_choice('comisiones::auditoria.total', $entries->total(), ['cantidad' => number_format($entries->total(), 0, ',', '.')])">
            <x-ui.table :caption="__('comisiones::auditoria.tabla')" :caption-hidden="true" :columns="$columns" :empty-message="__('comisiones::auditoria.vacio')">
                @foreach ($entries as $entry)
                    @php
                        $changes = AuditLabels::changes($entry->cambios ?? []);
                        $context = AuditLabels::context($entry->contexto ?? []);
                    @endphp
                    <tr data-entry="{{ $entry->id }}" data-event="{{ $entry->evento }}">
                        <td><x-ui.date :value="$entry->created_at" :time="true" /></td>
                        <td class="min-w-36">{{ $entry->usuario ?: AuditTrail::SYSTEM }}</td>
                        <td class="min-w-44">
                            <span class="font-semibold">{{ AuditLabels::event($entry->evento) }}</span>
                        </td>
                        <td class="whitespace-nowrap">{{ $entry->periodo ?? '—' }}</td>
                        <td class="min-w-32">{{ AuditLabels::subject($entry->sujeto_tipo, $entry->sujeto_id) ?? '—' }}</td>
                        <td class="min-w-40 [overflow-wrap:anywhere]">{{ $entry->motivo ?? '—' }}</td>
                        <td class="min-w-56">
                            @if ($changes === [] && $context === [] && $entry->ip === null)
                                <span class="text-fg-muted">{{ __('comisiones::auditoria.sin_detalle') }}</span>
                            @else
                                <details>
                                    <summary class="cursor-pointer font-semibold text-brand-ink">{{ __('comisiones::auditoria.ver_detalle') }}</summary>
                                    <div class="mt-2 flex flex-col gap-2 text-sm [overflow-wrap:anywhere]">
                                        @if ($changes !== [])
                                            <div>
                                                <p class="font-semibold text-fg-muted">{{ __('comisiones::auditoria.cambios') }}</p>
                                                <ul class="flex flex-col gap-0.5">
                                                    @foreach ($changes as $change)
                                                        <li><span class="font-semibold">{{ $change['field'] }}</span>: {{ $change['value'] }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        @if ($context !== [])
                                            <div>
                                                <p class="font-semibold text-fg-muted">{{ __('comisiones::auditoria.contexto') }}</p>
                                                <ul class="flex flex-col gap-0.5">
                                                    @foreach ($context as $line)
                                                        <li>{{ $line }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        @if ($entry->ip !== null)
                                            <p class="text-fg-muted">{{ __('comisiones::auditoria.ip', ['ip' => $entry->ip]) }}</p>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-ui.pagination :paginator="$entries" />
        </x-ui.card>
    </div>
</x-layouts.app>
