@use('App\Modules\Cartera\Domain\Dashboard\AgingSlices')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    GET /cartera (cartera.tablero): the dashboard of the portfolio manager
    and the analyst. $dashboard: Application\Dashboard\Dashboard, already
    limited to the companies of the user's current view.
--}}
@php
    $percent = fn (float $value): string => number_format($value, 1, ',', '.').' %';

    $topColumns = [
        ['key' => 'client', 'label' => __('cartera::tablero.principales.columnas.cliente')],
        ...($dashboard->isGroup ? [['key' => 'company', 'label' => __('cartera::tablero.principales.columnas.empresa')]] : []),
        ['key' => 'overdue', 'label' => __('cartera::tablero.principales.columnas.vencido'), 'align' => 'end'],
        ['key' => 'balance', 'label' => __('cartera::tablero.principales.columnas.total'), 'align' => 'end'],
        ['key' => 'days', 'label' => __('cartera::tablero.principales.columnas.dias'), 'align' => 'end'],
        ['key' => 'status', 'label' => __('cartera::tablero.principales.columnas.estado')],
        ['key' => 'advisor', 'label' => __('cartera::tablero.principales.columnas.asesor')],
    ];

    $agingColumns = [
        ['key' => 'band', 'label' => __('cartera::tablero.edades.columnas.edad')],
        ['key' => 'amount', 'label' => __('cartera::tablero.edades.columnas.valor'), 'align' => 'end'],
        ['key' => 'share', 'label' => __('cartera::tablero.edades.columnas.parte'), 'align' => 'end'],
    ];

    $comparisonColumns = [
        ['key' => 'metric', 'label' => __('cartera::tablero.comparacion.metrica')],
        ...array_map(fn ($figures): array => ['key' => $figures->company->code->value, 'label' => $figures->company->code->value, 'align' => 'end'], $dashboard->comparison),
    ];
@endphp

<x-layouts.app :title="__('cartera::tablero.titulo')">
    <x-ui.page-header
        :title="__('cartera::tablero.titulo')"
        :context="__('cartera::tablero.contexto', ['empresa' => $dashboard->scopeLabel, 'fecha' => DateFormat::date($dashboard->today)])"
        :description="__('cartera::tablero.descripcion')"
    />

    @if (! $dashboard->hasPortfolio())
        <x-ui.empty icon="chart" :title="__('cartera::tablero.sin_cartera.titulo')" :description="__('cartera::tablero.sin_cartera.detalle')" />
    @else
        <div class="grid gap-6">
            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
                <x-ui.stat
                    :label="__('cartera::tablero.indicadores.total.etiqueta')"
                    :hint="trans_choice('cartera::tablero.indicadores.total.pista', $dashboard->totals->clientCount)"
                >
                    <x-ui.money :value="$dashboard->totals->total" />
                </x-ui.stat>

                <x-ui.stat
                    :label="__('cartera::tablero.indicadores.vencido.etiqueta')"
                    :tone="$dashboard->risk->tone()"
                    :href="route('cartera.clientes.index', ['vencidos' => 1])"
                    :hint="__('cartera::tablero.indicadores.vencido.pista', [
                        'porcentaje' => $percent($dashboard->totals->overduePercent()),
                        'riesgo' => __('cartera::tablero.riesgo.'.$dashboard->risk->value),
                        'clientes' => trans_choice('cartera::tablero.indicadores.vencido.clientes', $dashboard->totals->overdueClientCount),
                    ])"
                >
                    <x-ui.money :value="$dashboard->totals->overdue" />
                </x-ui.stat>

                <x-ui.stat
                    :label="__('cartera::tablero.indicadores.compromisos.etiqueta')"
                    :value="(string) $dashboard->weekCommitmentsCount"
                    :href="route('cartera.compromisos.index', ['hasta' => $dashboard->today->addDays(6)->toDateString()])"
                    :hint="trans_choice('cartera::tablero.indicadores.compromisos.pista', $dashboard->weekCommitmentsCount, ['valor' => Money::format($dashboard->weekCommitmentsAmount)])"
                />

                <x-ui.stat
                    :label="__('cartera::tablero.indicadores.sin_gestion.etiqueta')"
                    :value="(string) $dashboard->unmanagedOverdue"
                    :tone="$dashboard->unmanagedOverdue > 0 ? 'warning' : 'success'"
                    :href="route('cartera.clientes.index', ['sin_gestion' => 1])"
                    :hint="trans_choice('cartera::tablero.indicadores.sin_gestion.pista', $dashboard->unmanagedOverdue, ['dias' => $dashboard->unmanagedDays])"
                />
            </div>

            @if ($dashboard->comparison !== [])
                <x-ui.card :title="__('cartera::tablero.comparacion.titulo')" :description="__('cartera::tablero.comparacion.descripcion')">
                    <div class="grid gap-6">
                        <x-ui.table :caption="__('cartera::tablero.comparacion.tabla')" :caption-hidden="true" :columns="$comparisonColumns">
                            <tr>
                                <th scope="row">{{ __('cartera::tablero.comparacion.filas.total') }}</th>
                                @foreach ($dashboard->comparison as $figures)
                                    <td data-align="end"><x-ui.money :value="$figures->totals->total" /></td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ __('cartera::tablero.comparacion.filas.vencido') }}</th>
                                @foreach ($dashboard->comparison as $figures)
                                    <td data-align="end"><x-ui.money :value="$figures->totals->overdue" /></td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ __('cartera::tablero.comparacion.filas.porcentaje') }}</th>
                                @foreach ($dashboard->comparison as $figures)
                                    <td data-align="end">{{ $percent($figures->totals->overduePercent()) }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ __('cartera::tablero.comparacion.filas.riesgo') }}</th>
                                @foreach ($dashboard->comparison as $figures)
                                    <td data-align="end">
                                        <x-ui.chip :tone="$figures->risk->tone()">{{ __('cartera::tablero.comparacion.riesgo', ['nivel' => __('cartera::tablero.riesgo.'.$figures->risk->value)]) }}</x-ui.chip>
                                    </td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ __('cartera::tablero.comparacion.filas.clientes') }}</th>
                                @foreach ($dashboard->comparison as $figures)
                                    <td data-align="end">{{ number_format($figures->totals->clientCount, 0, ',', '.') }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ __('cartera::tablero.comparacion.filas.clientes_vencidos') }}</th>
                                @foreach ($dashboard->comparison as $figures)
                                    <td data-align="end">{{ number_format($figures->totals->overdueClientCount, 0, ',', '.') }}</td>
                                @endforeach
                            </tr>
                        </x-ui.table>

                        <div class="grid gap-6 md:grid-cols-2">
                            @foreach ($dashboard->comparison as $figures)
                                <div>
                                    <h3 class="mb-3 text-sm font-semibold text-fg-soft">{{ __('cartera::tablero.comparacion.composicion', ['empresa' => $figures->company->name]) }}</h3>
                                    <x-cartera::aging-bar
                                        :slices="AgingSlices::from($figures->totals->aging)"
                                        :label="__('cartera::tablero.comparacion.composicion', ['empresa' => $figures->company->name])"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-ui.card>
            @endif

            <div class="grid items-start gap-6 lg:grid-cols-2">
                <x-ui.card :title="__('cartera::tablero.edades.titulo')" :description="__('cartera::tablero.edades.descripcion')">
                    <div class="grid gap-5">
                        @if ($dashboard->totals->overdue > 0)
                            <x-cartera::aging-bar :slices="$dashboard->aging" :label="__('cartera::tablero.edades.grafico')" />

                            <x-ui.table :caption="__('cartera::tablero.edades.tabla')" :caption-hidden="true" :columns="$agingColumns">
                                @foreach ($dashboard->aging as $slice)
                                    <tr>
                                        <th scope="row">{{ __('cartera::tablero.edades.bandas.'.$slice->band->value) }}</th>
                                        <td data-align="end"><x-ui.money :value="$slice->amount" /></td>
                                        <td data-align="end">{{ $percent($slice->share) }}</td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        @else
                            <p class="text-fg-muted">{{ __('cartera::tablero.edades.vacio') }}</p>
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.card :title="__('cartera::tablero.semana.titulo')" :description="__('cartera::tablero.semana.descripcion')">
                    <div>
                        @forelse ($dashboard->weekCommitments as $commitment)
                            <div class="flex items-start justify-between gap-4 border-b border-line py-3 first:pt-0 last:border-b-0 last:pb-0">
                                <div class="min-w-0">
                                    <p><x-cartera::client-link :id="$commitment->clientId" :name="$commitment->clientName" /></p>
                                    <p class="text-sm text-fg-muted">
                                        {{ __('cartera::tablero.semana.vence') }}
                                        @if ($commitment->dueDate->isSameDay($dashboard->today))
                                            {{ __('cartera::tablero.semana.hoy') }}
                                        @else
                                            <x-ui.date :value="$commitment->dueDate" />
                                        @endif
                                    </p>
                                </div>
                                <p class="shrink-0 font-semibold tabular-nums"><x-ui.money :value="$commitment->amount" /></p>
                            </div>
                        @empty
                            <x-ui.empty icon="calendar-check" :title="__('cartera::tablero.semana.vacio.titulo')" :description="__('cartera::tablero.semana.vacio.detalle')" />
                        @endforelse
                    </div>
                </x-ui.card>
            </div>

            <x-ui.card :title="__('cartera::tablero.principales.titulo')" :description="__('cartera::tablero.principales.descripcion')">
                <div>
                    @if ($dashboard->topDebtors === [])
                        <x-ui.empty icon="success" :title="__('cartera::tablero.principales.vacio.titulo')" :description="__('cartera::tablero.principales.vacio.detalle')" />
                    @else
                        <x-ui.table :caption="__('cartera::tablero.principales.tabla')" :caption-hidden="true" :columns="$topColumns">
                            @foreach ($dashboard->topDebtors as $client)
                                <tr>
                                    <td><x-cartera::client-link :id="$client->id" :name="$client->name" /></td>
                                    @if ($dashboard->isGroup)
                                        <td><x-ui.chip :dot="false">{{ $client->company->value }}</x-ui.chip></td>
                                    @endif
                                    <td data-align="end"><x-ui.money :value="$client->overdue" /></td>
                                    <td data-align="end"><x-ui.money :value="$client->balance" /></td>
                                    <td data-align="end">{{ number_format($client->maxDaysOverdue, 0, ',', '.') }}</td>
                                    <td><x-ui.badge-state :state="$client->status" /></td>
                                    <td>{{ $client->advisor?->name ?? __('cartera::tablero.principales.sin_asesor') }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card :title="__('cartera::tablero.actividad.titulo')" :description="__('cartera::tablero.actividad.descripcion')">
                <div>
                    @forelse ($dashboard->recentActivity as $activity)
                        <div class="flex items-start gap-3 border-b border-line py-3 first:pt-0 last:border-b-0 last:pb-0">
                            <div class="min-w-0 flex-1">
                                <p><x-cartera::client-link :id="$activity->clientId" :name="$activity->clientName" /></p>
                                <p class="text-sm text-fg-soft [overflow-wrap:anywhere]">
                                    <x-ui.chip :dot="false">{{ $activity->type->label() }}</x-ui.chip>
                                    {{ $activity->result ?? $activity->summary }}
                                </p>
                                <p class="text-sm text-fg-muted">
                                    <x-ui.date :value="$activity->occurredAt" :time="true" />
                                    @if ($activity->user !== null)
                                        · {{ __('cartera::tablero.actividad.por', ['usuario' => $activity->user->name]) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty icon="history" :title="__('cartera::tablero.actividad.vacio.titulo')" :description="__('cartera::tablero.actividad.vacio.detalle')" />
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    @endif
</x-layouts.app>
