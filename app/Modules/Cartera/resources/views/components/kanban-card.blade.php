{{--
    Card of one client on the Kanban board (<x-cartera::kanban-card>), a <li>
    of the column list. With $canMove it can be dragged and carries the
    "Mover" button, the way to change the status from the keyboard or a phone.
    The data-* attributes are what the board script reads.

    $client: ClientSummary. $showCompany: the group view names the company.
--}}
@props(['client', 'showCompany' => false, 'canMove' => false])

<li
    {{ $attributes->class('rounded-card border border-line bg-surface p-3 shadow-sm') }}
    data-card="{{ $client->id }}"
    data-name="{{ $client->name }}"
    data-status="{{ $client->status->value }}"
    data-balance="{{ $client->balance }}"
    @if ($canMove)
        draggable="true"
        x-on:dragstart="dragStart($event)"
        x-on:dragend="dragEnd()"
    @endif
>
    <div class="flex items-start justify-between gap-2">
        <p class="font-semibold [overflow-wrap:anywhere]">{{ $client->name }}</p>
        @if ($showCompany)
            <x-ui.chip :dot="false">{{ $client->company->value }}</x-ui.chip>
        @endif
    </div>

    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
        <div>
            <dt class="text-fg-muted">{{ __('cartera::kanban.tarjeta.vencido') }}</dt>
            <dd class="font-semibold tabular-nums"><x-ui.money :value="$client->overdue" /></dd>
        </div>
        <div>
            <dt class="text-fg-muted">{{ __('cartera::kanban.tarjeta.total') }}</dt>
            <dd class="font-semibold tabular-nums"><x-ui.money :value="$client->balance" /></dd>
        </div>
        <div>
            <dt class="text-fg-muted">{{ __('cartera::kanban.tarjeta.mora') }}</dt>
            <dd>{{ $client->maxDaysOverdue > 0 ? trans_choice('cartera::kanban.tarjeta.dias', $client->maxDaysOverdue) : __('cartera::kanban.tarjeta.al_dia') }}</dd>
        </div>
        <div>
            <dt class="text-fg-muted">{{ __('cartera::kanban.tarjeta.ultima_gestion') }}</dt>
            <dd>
                @if ($client->lastActivityAt !== null)
                    <x-ui.date :value="$client->lastActivityAt" />
                @elseif ($client->overdue > 0)
                    <x-ui.chip tone="warning">{{ __('cartera::kanban.tarjeta.sin_gestion') }}</x-ui.chip>
                @else
                    —
                @endif
            </dd>
        </div>
    </dl>

    <p class="mt-2 text-sm text-fg-muted">
        {{ __('cartera::kanban.tarjeta.asesor') }}: {{ $client->advisor?->name ?? __('cartera::kanban.tarjeta.sin_asignar') }}
        · {{ __('cartera::kanban.tarjeta.gestor') }}: {{ $client->manager?->name ?? __('cartera::kanban.tarjeta.sin_asignar') }}
    </p>

    @if ($canMove)
        <x-ui.button
            variant="secondary"
            class="mt-3 w-full"
            data-move-button
            data-modal-open="mover-cliente"
            x-on:click="pick($el.closest('[data-card]'))"
        >{{ __('cartera::kanban.tarjeta.mover') }}<span data-visually-hidden> · {{ __('cartera::kanban.tarjeta.mover_a', ['cliente' => $client->name]) }}</span></x-ui.button>
    @endif
</li>
