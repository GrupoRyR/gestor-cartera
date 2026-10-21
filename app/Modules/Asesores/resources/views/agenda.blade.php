@use('App\Modules\Asesores\Application\Agenda\AgendaReader')
@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')
@use('App\Support\Formatting\Money')

{{--
    GET /asesores/agenda (asesores.agenda): the open commitments of the
    portfolio the user may see, in four groups by date: broken, due today,
    next seven days and later. $page->agenda holds them already grouped;
    sales managers can narrow it to one advisor of the team.
--}}
@php
    $agenda = $page->agenda;

    $columns = [
        ['key' => 'cliente', 'label' => __('asesores::agenda.tabla.cliente')],
        ['key' => 'fecha', 'label' => __('asesores::agenda.tabla.fecha')],
        ['key' => 'valor', 'label' => __('asesores::agenda.tabla.valor'), 'align' => 'end'],
        ['key' => 'estado', 'label' => __('asesores::agenda.tabla.estado')],
        ['key' => 'acciones', 'label' => __('asesores::agenda.tabla.acciones'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('asesores::agenda.titulo')">
    <x-ui.page-header :title="__('asesores::agenda.titulo')" :description="__('asesores::agenda.descripcion')" />

    <div class="mt-6 grid gap-6">
        <x-asesores::advisor-filter :advisors="$page->advisors" :filter="$filter" :action="route('asesores.agenda')" />

        @if ($page->truncated)
            <x-ui.alert tone="info">{{ __('asesores::agenda.truncada', ['limite' => AgendaReader::LIMIT]) }}</x-ui.alert>
        @endif

        @if ($agenda->total() === 0)
            <x-ui.empty icon="calendar-check" :title="__('asesores::agenda.vacio.titulo')" :description="__('asesores::agenda.vacio.detalle')">
                <x-ui.link-button :href="route('asesores.clientes.index')" variant="secondary" icon="users">{{ __('asesores::comun.nav.clientes') }}</x-ui.link-button>
            </x-ui.empty>
        @else
            <section aria-label="{{ __('asesores::agenda.resumen.titulo') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <x-ui.stat
                    :label="__('asesores::agenda.resumen.incumplidos')"
                    :value="(string) count($agenda->broken)"
                    :hint="Money::format($agenda->amountOf('broken'))"
                    :tone="$agenda->broken === [] ? 'neutral' : 'danger'"
                />
                <x-ui.stat :label="__('asesores::agenda.resumen.hoy')" :value="(string) count($agenda->today)" :hint="Money::format($agenda->amountOf('today'))" :tone="$agenda->today === [] ? 'neutral' : 'warning'" />
                <x-ui.stat :label="__('asesores::agenda.resumen.semana')" :value="(string) count($agenda->week)" :hint="Money::format($agenda->amountOf('week'))" />
            </section>

            @foreach ($agenda->groups() as $group => $commitments)
                @continue($commitments === [])

                <x-ui.card :title="__('asesores::agenda.grupos.'.$group.'.titulo')" :description="__('asesores::agenda.grupos.'.$group.'.ayuda')" data-group="{{ $group }}">
                    <x-ui.table
                        :caption="__('asesores::agenda.grupos.titulo_con_total', ['titulo' => __('asesores::agenda.grupos.'.$group.'.titulo'), 'cantidad' => trans_choice('asesores::agenda.resumen.cantidad', count($commitments), ['cantidad' => count($commitments)]), 'valor' => Money::format($agenda->amountOf($group))])"
                        :caption-hidden="true"
                        :columns="$columns"
                    >
                        @foreach ($commitments as $commitment)
                            @php($days = (int) $today->startOfDay()->diffInDays($commitment->dueDate->startOfDay(), false))
                            <tr>
                                <td class="min-w-40">
                                    <a href="{{ route('asesores.clientes.show', $commitment->clientId) }}" class="block min-h-touch font-semibold text-brand-ink underline-offset-4 hover:underline [overflow-wrap:anywhere]">{{ $commitment->clientName }}</a>
                                </td>
                                <td>
                                    <x-ui.date :value="$commitment->dueDate" />
                                    <span class="block text-xs text-fg-muted">
                                        @if ($days === 0)
                                            {{ __('asesores::agenda.cuando.hoy') }}
                                        @elseif ($days < 0)
                                            {{ trans_choice('asesores::agenda.cuando.atras', abs($days), ['dias' => abs($days)]) }}
                                        @else
                                            {{ trans_choice('asesores::agenda.cuando.adelante', $days, ['dias' => $days]) }}
                                        @endif
                                    </span>
                                </td>
                                <td data-align="end"><x-ui.money :value="$commitment->amount" class="font-semibold" /></td>
                                <td>
                                    <x-ui.chip :tone="$commitment->status === CommitmentStatus::Pending ? 'info' : 'danger'">{{ $commitment->status->label() }}</x-ui.chip>
                                </td>
                                <td data-align="end">
                                    <x-ui.link-button :href="route('asesores.compromisos.show', $commitment->id)" variant="ghost">
                                        @can('fulfill', $commitment){{ __('asesores::compromisos.ver') }}@else{{ __('asesores::compromisos.detalle') }}@endcan
                                        <span data-visually-hidden>&nbsp;{{ $commitment->clientName }}</span>
                                    </x-ui.link-button>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endforeach
        @endif
    </div>
</x-layouts.app>
