@use('App\Modules\Core\Contracts\Enums\ClientStatus')
@use('App\Support\Formatting\Money')

{{--
    GET /cartera/kanban (cartera.kanban): the clients laid out in one column
    per status. $board: Domain\Kanban\KanbanBoard; $canMove: the user may
    change statuses; $isGroup: name the company on each card.

    Moving a card: drag it to another column, or press "Mover" and pick the
    status in the dialog (keyboard, screen readers and phones, where dragging
    does not work). Both call POST cartera.clientes.estado; the page then
    moves the card itself. "Al día" and "Pago recibido" start hidden and the
    choice is kept in the browser.
--}}
@php
    $state = [];

    foreach ($board->columns as $column) {
        $state[$column->status->value] = ['count' => $column->count, 'total' => $column->total];
    }

    $labels = [
        'none' => trans_choice('cartera::kanban.columna.clientes', 0),
        'one' => trans_choice('cartera::kanban.columna.clientes', 1),
        'many' => trans_choice('cartera::kanban.columna.clientes', 2, ['count' => ':count']),
        'question' => __('cartera::kanban.mover.pregunta', ['cliente' => ':cliente']),
        'error' => __('cartera::kanban.mensajes.error'),
    ];
@endphp

<x-layouts.app :title="__('cartera::kanban.titulo')">
    <x-ui.page-header :title="__('cartera::kanban.titulo')" :description="__('cartera::kanban.descripcion')" />

    <div
        x-data="kanbanBoard(@js(['columns' => $state, 'labels' => $labels, 'moveUrl' => route('cartera.clientes.estado', 0)]))"
        class="grid gap-4"
    >
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <x-ui.checkbox name="mostrar_cerradas" :label="__('cartera::kanban.mostrar_cerradas')" x-model="showClosed" />
            @if ($canMove)
                <p class="text-sm text-fg-muted">{{ __('cartera::kanban.tarjeta.arrastrar') }}</p>
            @endif
        </div>

        <x-ui.alert tone="success" hidden x-bind:hidden="! notice"><span x-text="notice"></span></x-ui.alert>
        <x-ui.alert tone="danger" hidden x-bind:hidden="! error"><span x-text="error"></span></x-ui.alert>

        <div role="region" tabindex="0" aria-label="{{ __('cartera::kanban.tablero') }}" class="flex snap-x snap-mandatory items-start gap-4 overflow-x-auto pb-4">
            @foreach ($board->columns as $column)
                @php($status = $column->status)
                <section
                    aria-labelledby="columna-{{ $status->value }}"
                    data-column="{{ $status->value }}"
                    @if ($column->hiddenByDefault) hidden x-bind:hidden="! showClosed" @endif
                    class="flex w-[85%] max-w-80 shrink-0 snap-start flex-col gap-3 rounded-card border border-line bg-subtle p-3 sm:w-72"
                >
                    <header>
                        <h2 id="columna-{{ $status->value }}" class="text-base font-bold">{{ $status->label() }}</h2>
                        <p class="text-sm text-fg-muted" x-text="summary('{{ $status->value }}')">{{ __('cartera::kanban.columna.resumen', [
                            'clientes' => trans_choice('cartera::kanban.columna.clientes', $column->count),
                            'total' => Money::format($column->total),
                        ]) }}</p>
                    </header>

                    <ul
                        data-column-list="{{ $status->value }}"
                        class="grid min-h-16 content-start gap-3 rounded-card"
                        x-bind:class="over === '{{ $status->value }}' && 'ring-2 ring-brand-primary'"
                        @if ($canMove)
                            x-on:dragover.prevent="over = '{{ $status->value }}'"
                            x-on:dragleave="over = null"
                            x-on:drop.prevent="drop('{{ $status->value }}')"
                        @endif
                    >
                        @foreach ($column->cards as $client)
                            <x-cartera::kanban-card :client="$client" :show-company="$isGroup" :can-move="$canMove" />
                        @endforeach
                    </ul>

                    <p class="text-sm text-fg-muted" @if ($column->count > 0) hidden @endif x-bind:hidden="columns['{{ $status->value }}'].count > 0">{{ __('cartera::kanban.columna.vacia') }}</p>

                    @if ($column->hasMore())
                        <p class="text-sm text-fg-muted">
                            {{ trans_choice('cartera::kanban.columna.mas', $column->remaining()) }}.
                            <a href="{{ route('cartera.clientes.index', ['estado' => $status->value]) }}" class="font-semibold text-brand-ink underline">{{ __('cartera::kanban.columna.ver_todos') }}</a>
                        </p>
                    @endif
                </section>
            @endforeach
        </div>

        @if ($canMove)
            <x-ui.modal id="mover-cliente" :title="__('cartera::kanban.mover.titulo')">
                <p class="mb-4" x-text="picked ? labels.question.replace(':cliente', picked.name) : ''"></p>

                <ul class="grid gap-2">
                    @foreach (ClientStatus::cases() as $option)
                        <li>
                            <x-ui.button
                                variant="secondary"
                                class="w-full justify-between"
                                data-modal-close
                                x-on:click="move('{{ $option->value }}')"
                                x-bind:disabled="picked && picked.status === '{{ $option->value }}'"
                            >
                                <span>{{ $option->label() }}</span>
                                <span class="font-normal text-fg-muted" hidden x-bind:hidden="! (picked && picked.status === '{{ $option->value }}')">{{ __('cartera::kanban.mover.actual') }}</span>
                            </x-ui.button>
                        </li>
                    @endforeach
                </ul>

                <x-slot:footer>
                    <x-ui.button variant="ghost" data-modal-close>{{ __('cartera::kanban.mover.cancelar') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>

    @verbatim
        <script>
            (() => {
                const register = () => window.Alpine.data('kanbanBoard', (config) => ({
                    columns: config.columns,
                    labels: config.labels,
                    moveUrl: config.moveUrl,
                    showClosed: false,
                    picked: null,
                    dragged: null,
                    over: null,
                    busy: false,
                    notice: '',
                    error: '',

                    init() {
                        try {
                            this.showClosed = window.localStorage.getItem('cartera.kanban.cerradas') === '1';
                        } catch (e) { /* storage blocked: start hidden */ }

                        this.$watch('showClosed', (value) => {
                            try {
                                window.localStorage.setItem('cartera.kanban.cerradas', value ? '1' : '0');
                            } catch (e) { /* storage blocked: the choice lasts until reload */ }
                        });
                    },

                    summary(status) {
                        const column = this.columns[status];
                        const clients = column.count === 0 ? this.labels.none
                            : column.count === 1 ? this.labels.one
                            : this.labels.many.replace(':count', column.count);

                        return clients + ' · ' + this.money(column.total);
                    },

                    // Same shape as App\Support\Formatting\Money: -$ 1.234.567 with a no-break space.
                    money(value) {
                        const digits = String(Math.round(Math.abs(value))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

                        return (value < 0 ? '-' : '') + '$ ' + digits;
                    },

                    read(card) {
                        return { id: card.dataset.card, name: card.dataset.name, status: card.dataset.status, balance: Number(card.dataset.balance) };
                    },

                    pick(card) {
                        this.picked = this.read(card);
                    },

                    dragStart(event) {
                        this.dragged = this.read(event.currentTarget);
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', this.dragged.name);
                    },

                    dragEnd() {
                        this.dragged = null;
                        this.over = null;
                    },

                    drop(status) {
                        const card = this.dragged;
                        this.dragged = null;
                        this.over = null;

                        if (card) {
                            this.move(status, card);
                        }
                    },

                    async move(status, card = this.picked) {
                        if (!card || card.status === status || this.busy) {
                            return;
                        }

                        this.busy = true;
                        this.error = '';
                        this.notice = '';

                        try {
                            const response = await window.apiFetch(this.moveUrl.replace('/0/', '/' + card.id + '/'), {
                                method: 'POST',
                                body: { estado: status },
                            });
                            const body = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                this.error = body.message || this.labels.error;

                                return;
                            }

                            this.relocate(card, status);
                            this.notice = body.message;
                        } catch (e) {
                            this.error = this.labels.error;
                        } finally {
                            this.busy = false;
                        }
                    },

                    // Moves the card to the new column and keeps the counts and totals in step.
                    relocate(card, status) {
                        const element = this.$root.querySelector('[data-card="' + card.id + '"]');
                        const list = this.$root.querySelector('[data-column-list="' + status + '"]');

                        if (element && list) {
                            element.dataset.status = status;
                            list.prepend(element);
                            element.querySelector('[data-move-button]')?.focus();
                        }

                        this.columns[card.status].count -= 1;
                        this.columns[card.status].total -= card.balance;
                        this.columns[status].count += 1;
                        this.columns[status].total += card.balance;
                    },
                }));

                if (window.Alpine) {
                    register();
                } else {
                    document.addEventListener('alpine:init', register);
                }
            })();
        </script>
    @endverbatim
</x-layouts.app>
