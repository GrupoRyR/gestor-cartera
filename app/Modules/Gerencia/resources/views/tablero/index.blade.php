@use('App\Modules\Gerencia\Domain\Period\PeriodKey')
@use('App\Modules\Gerencia\Http\Presenters\CollectionsChart')
@use('App\Modules\Gerencia\Http\Presenters\Figures')
@use('App\Modules\Gerencia\Http\Presenters\Tones')
@use('App\Modules\Gerencia\Http\Presenters\TrendChart')
@use('App\Support\Formatting\DateFormat')
@use('Illuminate\Support\Facades\Route')

{{--
    GET /gerencia (gerencia.tablero): the management dashboard of the
    portfolio manager. $board: Application\Board\ManagementBoard, already
    limited to the companies of the user's current view. The first scope is
    the one the headline figures use: the group when the user sees two
    companies, else the single company.
--}}
@php
    $scopes = $board->scopes;
    $scope = $scopes[0] ?? null;
    $isGroup = count($scopes) > 1;
    $period = $board->period;

    $periodLabel = __('gerencia::tablero.periodos.'.$period->key->value);
    $periodDates = __('gerencia::tablero.periodos.fechas', ['desde' => DateFormat::date($period->from), 'hasta' => DateFormat::date($period->to)]);

    $tabs = array_map(fn (PeriodKey $key): array => [
        'key' => $key->value,
        'label' => __('gerencia::tablero.periodos.'.$key->value),
        'href' => route('gerencia.tablero', ['periodo' => $key->value]),
    ], PeriodKey::cases());

    $rows = $board->compliance->rows;
    $evaluated = $board->compliance->evaluated();
    $fulfilled = $board->compliance->fulfilled();
    $complianceRate = $board->compliance->overallRate();

    $mailColumns = [
        ['key' => 'company', 'label' => __('gerencia::tablero.cobro.columnas.empresa')],
        ['key' => 'runs', 'label' => __('gerencia::tablero.cobro.columnas.lotes'), 'align' => 'end'],
        ['key' => 'sent', 'label' => __('gerencia::tablero.cobro.columnas.enviados'), 'align' => 'end'],
        ['key' => 'delivered', 'label' => __('gerencia::tablero.cobro.columnas.entregados'), 'align' => 'end'],
        ['key' => 'bounced', 'label' => __('gerencia::tablero.cobro.columnas.rebotados'), 'align' => 'end'],
        ['key' => 'bounce_rate', 'label' => __('gerencia::tablero.cobro.columnas.tasa_rebote'), 'align' => 'end'],
        ['key' => 'replies', 'label' => __('gerencia::tablero.cobro.columnas.respuestas'), 'align' => 'end'],
        ['key' => 'reply_rate', 'label' => __('gerencia::tablero.cobro.columnas.tasa_respuesta'), 'align' => 'end'],
        ['key' => 'without_email', 'label' => __('gerencia::tablero.cobro.columnas.sin_correo'), 'align' => 'end'],
        ['key' => 'failed', 'label' => __('gerencia::tablero.cobro.columnas.fallidos'), 'align' => 'end'],
    ];
    $collections = $board->collections;
    $collectionsChart = CollectionsChart::from($collections, $period);
    $collectionsTone = TrendChart::tone($isGroup ? null : $scope?->company);
    $granularity = __('gerencia::tablero.recaudo.'.($collectionsChart['weekly'] ? 'por_semana' : 'por_dia'));

    $comparisonColumns = $isGroup ? [
        ['key' => 'metric', 'label' => __('gerencia::tablero.comparacion.metrica')],
        ...array_map(fn ($figures): array => ['key' => $figures->company?->value ?? 'group', 'label' => $figures->name, 'align' => 'end'], $scopes),
    ] : [];

    $trend = TrendChart::from($scopes);
    $trendColumns = [
        ['key' => 'month', 'label' => __('gerencia::tablero.tendencia.columnas.mes')],
        ['key' => 'total', 'label' => __('gerencia::tablero.tendencia.columnas.total'), 'align' => 'end'],
        ['key' => 'overdue', 'label' => __('gerencia::tablero.tendencia.columnas.vencido'), 'align' => 'end'],
        ...array_map(fn ($figures): array => [
            'key' => 'percent-'.($figures->company?->value ?? 'group'),
            'label' => count($scopes) > 1
                ? __('gerencia::tablero.tendencia.columnas.porcentaje_empresa', ['empresa' => $figures->name])
                : __('gerencia::tablero.tendencia.columnas.porcentaje'),
            'align' => 'end',
        ], $scopes),
    ];

    $complianceColumns = [
        ['key' => 'advisor', 'label' => __('gerencia::tablero.cumplimiento.columnas.asesor')],
        ...($isGroup ? [['key' => 'company', 'label' => __('gerencia::tablero.cumplimiento.columnas.empresa')]] : []),
        ['key' => 'evaluated', 'label' => __('gerencia::tablero.cumplimiento.columnas.evaluados'), 'align' => 'end'],
        ['key' => 'fulfilled', 'label' => __('gerencia::tablero.cumplimiento.columnas.cumplidos'), 'align' => 'end'],
        ['key' => 'on_time', 'label' => __('gerencia::tablero.cumplimiento.columnas.a_tiempo'), 'align' => 'end'],
        ['key' => 'broken', 'label' => __('gerencia::tablero.cumplimiento.columnas.incumplidos'), 'align' => 'end'],
        ['key' => 'overdue', 'label' => __('gerencia::tablero.cumplimiento.columnas.vencidos'), 'align' => 'end'],
        ['key' => 'upcoming', 'label' => __('gerencia::tablero.cumplimiento.columnas.proximos'), 'align' => 'end'],
        ['key' => 'amount', 'label' => __('gerencia::tablero.cumplimiento.columnas.valor'), 'align' => 'end'],
        ['key' => 'rate', 'label' => __('gerencia::tablero.cumplimiento.columnas.cumplimiento'), 'align' => 'end'],
    ];

    $weeklyColumns = [
        ['key' => 'week', 'label' => __('gerencia::tablero.recaudo.semanal.columnas.semana')],
        ...($isGroup ? [['key' => 'company', 'label' => __('gerencia::tablero.recaudo.semanal.columnas.empresa')]] : []),
        ['key' => 'total', 'label' => __('gerencia::tablero.recaudo.semanal.columnas.total'), 'align' => 'end'],
    ];

    $dailyColumns = [
        ['key' => 'date', 'label' => __('gerencia::tablero.recaudo.diario.columnas.fecha')],
        ['key' => 'total', 'label' => __('gerencia::tablero.recaudo.diario.columnas.total'), 'align' => 'end'],
    ];
@endphp

<x-layouts.app :title="__('gerencia::tablero.titulo')">
    <x-ui.page-header
        :title="__('gerencia::tablero.titulo')"
        :context="__('gerencia::tablero.contexto', ['empresa' => $board->scopeName, 'periodo' => $periodLabel.' · '.$periodDates])"
        :description="__('gerencia::tablero.descripcion')"
    />

    <div class="grid gap-6">
        <x-ui.tabs :label="__('gerencia::tablero.periodos.etiqueta')" :items="$tabs" :current="$period->key->value" />

        @if (! $board->hasPortfolio())
            <x-ui.empty icon="chart" :title="__('gerencia::tablero.sin_cartera.titulo')" :description="__('gerencia::tablero.sin_cartera.detalle')" />
        @else
            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
                <x-ui.stat
                    :label="__('gerencia::tablero.indicadores.total.etiqueta')"
                    :hint="trans_choice('gerencia::tablero.indicadores.total.pista', $scope->totals->clientCount)"
                >
                    <x-ui.money :value="$scope->totals->total" />
                </x-ui.stat>

                <x-ui.stat
                    :label="__('gerencia::tablero.indicadores.vencido.etiqueta')"
                    :tone="Tones::overdue($scope->totals->overduePercent())"
                    :hint="__('gerencia::tablero.indicadores.vencido.pista', [
                        'porcentaje' => Figures::percent($scope->totals->overduePercent()),
                        'clientes' => trans_choice('gerencia::tablero.indicadores.vencido.clientes', $scope->totals->overdueClientCount),
                    ])"
                >
                    <x-ui.money :value="$scope->totals->overdue" />
                </x-ui.stat>

                <x-ui.stat
                    :label="__('gerencia::tablero.indicadores.rotacion.etiqueta')"
                    :value="__('gerencia::tablero.indicadores.rotacion.valor', ['veces' => Figures::decimal($scope->rotation->times())])"
                    :hint="($scope->rotation->daysPerTurn() === null
                        ? __('gerencia::tablero.indicadores.rotacion.sin_vuelta')
                        : __('gerencia::tablero.indicadores.rotacion.dias', ['dias' => Figures::count((int) round($scope->rotation->daysPerTurn()))])
                    ).' '.__('gerencia::tablero.indicadores.rotacion.pista')"
                />

                <x-ui.stat
                    :label="__('gerencia::tablero.indicadores.cumplimiento.etiqueta')"
                    :value="$complianceRate === null ? '—' : Figures::percent($complianceRate)"
                    :tone="Tones::compliance($complianceRate)"
                    :hint="$complianceRate === null
                        ? __('gerencia::tablero.indicadores.cumplimiento.sin_datos')
                        : __('gerencia::tablero.indicadores.cumplimiento.pista', ['cumplidos' => Figures::count($fulfilled), 'evaluados' => Figures::count($evaluated)])"
                />
            </div>

            @if ($isGroup)
                <x-ui.card :title="__('gerencia::tablero.comparacion.titulo')" :description="__('gerencia::tablero.comparacion.descripcion')">
                    <x-ui.table :caption="__('gerencia::tablero.comparacion.tabla')" :caption-hidden="true" :columns="$comparisonColumns">
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.total') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end"><x-ui.money :value="$figures->totals->total" /></td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.vencido') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end"><x-ui.money :value="$figures->totals->overdue" /></td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.porcentaje') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end">
                                    <x-ui.chip :tone="Tones::overdue($figures->totals->overduePercent())">{{ Figures::percent($figures->totals->overduePercent()) }}</x-ui.chip>
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.clientes') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end">{{ Figures::count($figures->totals->clientCount) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.clientes_vencidos') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end">{{ Figures::count($figures->totals->overdueClientCount) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.recaudo') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end"><x-ui.money :value="$figures->rotation->collected" /></td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.rotacion') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end">{{ Figures::decimal($figures->rotation->times()) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('gerencia::tablero.comparacion.filas.dias') }}</th>
                            @foreach ($scopes as $figures)
                                <td data-align="end">{{ $figures->rotation->daysPerTurn() === null ? '—' : Figures::count((int) round($figures->rotation->daysPerTurn())) }}</td>
                            @endforeach
                        </tr>
                    </x-ui.table>
                </x-ui.card>
            @endif

            <x-ui.card :title="__('gerencia::tablero.tendencia.titulo')" :description="__('gerencia::tablero.tendencia.descripcion')">
                @if (count($trend['rows']) < 2)
                    <x-ui.empty icon="history" :title="__('gerencia::tablero.tendencia.vacio.titulo')" :description="__('gerencia::tablero.tendencia.vacio.detalle')" />
                @else
                    <div class="grid gap-4">
                        <x-gerencia::line-chart :series="$trend['series']" :label="__('gerencia::tablero.tendencia.grafico')" format="percent" />

                        <details class="text-sm">
                            <summary class="inline-flex min-h-touch cursor-pointer items-center font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('gerencia::tablero.tendencia.tabla_resumen') }}</summary>
                            <x-ui.table :caption="__('gerencia::tablero.tendencia.tabla')" :caption-hidden="true" :columns="$trendColumns">
                                @foreach ($trend['rows'] as $row)
                                    <tr>
                                        <th scope="row">{{ $row['month'] }}</th>
                                        <td data-align="end"><x-ui.money :value="$row['total']" /></td>
                                        <td data-align="end"><x-ui.money :value="$row['overdue']" /></td>
                                        @foreach ($row['percents'] as $percent)
                                            <td data-align="end">{{ $percent === null ? '—' : Figures::percent($percent) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </details>
                    </div>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card :title="__('gerencia::tablero.cumplimiento.titulo')" :description="__('gerencia::tablero.cumplimiento.descripcion')">
            <div class="grid gap-4">
                @if ($board->compliance->truncated)
                    <x-ui.alert tone="warning" :title="__('gerencia::tablero.cumplimiento.parcial.titulo')">{{ __('gerencia::tablero.cumplimiento.parcial.detalle') }}</x-ui.alert>
                @endif

                @if ($rows === [])
                    <x-ui.empty icon="calendar-check" :title="__('gerencia::tablero.cumplimiento.vacio.titulo')" :description="__('gerencia::tablero.cumplimiento.vacio.detalle')" />
                @else
                    <x-ui.table :caption="__('gerencia::tablero.cumplimiento.tabla')" :caption-hidden="true" :columns="$complianceColumns">
                        @foreach ($rows as $row)
                            @php
                                $advisor = $row->compliance;
                                $rate = $advisor->rate();
                            @endphp
                            <tr>
                                <th scope="row" class="min-w-40">{{ $row->advisorName ?? __('gerencia::tablero.cumplimiento.sin_asesor') }}</th>
                                @if ($isGroup)
                                    <td><x-ui.chip :dot="false">{{ $advisor->company->value }}</x-ui.chip></td>
                                @endif
                                <td data-align="end">{{ Figures::count($advisor->evaluated()) }}</td>
                                <td data-align="end">{{ Figures::count($advisor->fulfilled) }}</td>
                                <td data-align="end">{{ Figures::count($advisor->fulfilledOnTime) }}</td>
                                <td data-align="end">{{ Figures::count($advisor->broken) }}</td>
                                <td data-align="end">{{ Figures::count($advisor->overduePending) }}</td>
                                <td data-align="end">{{ Figures::count($advisor->upcoming) }}</td>
                                <td data-align="end"><x-ui.money :value="$advisor->amountFulfilled" /> / <x-ui.money :value="$advisor->amountEvaluated" /></td>
                                <td data-align="end">
                                    @if ($rate === null)
                                        —
                                    @else
                                        <x-ui.chip :tone="Tones::compliance($rate)">{{ Figures::percent($rate) }}</x-ui.chip>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card :title="__('gerencia::tablero.cobro.titulo')" :description="__('gerencia::tablero.cobro.descripcion')">
            <div class="grid gap-6">
                @foreach ($board->mail->kinds as $kindResults)
                    @php
                        $mail = $kindResults->total;
                        $kindLabel = $kindResults->kind->label();
                    @endphp
                    <section class="grid gap-3" aria-labelledby="cobro-{{ $kindResults->kind->value }}">
                        <h3 id="cobro-{{ $kindResults->kind->value }}" class="text-base font-bold">{{ $kindLabel }}</h3>
                        @if ($kindResults->kind === \App\Modules\Core\Contracts\Enums\MailRunKind::Circularization)
                            <p data-nota="circularizacion" class="text-sm text-fg-muted">{{ __('gerencia::tablero.cobro.nota_circularizacion') }}</p>
                        @endif

                        @if (! $kindResults->hasRuns())
                            <x-ui.empty icon="send" :title="__('gerencia::tablero.cobro.vacio.titulo')" :description="__('gerencia::tablero.cobro.vacio.detalle')" />
                        @else
                            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:grid-cols-3 md:gap-4 xl:grid-cols-6">
                                <x-ui.stat
                                    :label="__('gerencia::tablero.cobro.enviados.etiqueta')"
                                    :value="Figures::count($mail->sent)"
                                    :hint="trans_choice('gerencia::tablero.cobro.enviados.pista', $mail->runs)"
                                />
                                <x-ui.stat
                                    :label="__('gerencia::tablero.cobro.entregados.etiqueta')"
                                    :value="Figures::count($mail->delivered())"
                                    :hint="__('gerencia::tablero.cobro.entregados.pista')"
                                />
                                <x-ui.stat
                                    :label="__('gerencia::tablero.cobro.rebotes.etiqueta')"
                                    :value="Figures::count($mail->bounced)"
                                    :hint="$mail->bounceRate() === null ? __('gerencia::tablero.cobro.nada_enviado') : __('gerencia::tablero.cobro.rebotes.pista', ['porcentaje' => Figures::percent($mail->bounceRate())])"
                                />
                                <x-ui.stat
                                    :label="__('gerencia::tablero.cobro.respuestas.etiqueta')"
                                    :value="Figures::count($mail->replies)"
                                    :hint="$mail->replyRate() === null ? __('gerencia::tablero.cobro.nada_enviado') : __('gerencia::tablero.cobro.respuestas.pista', ['porcentaje' => Figures::percent($mail->replyRate())])"
                                />
                                <x-ui.stat
                                    :label="__('gerencia::tablero.cobro.sin_correo.etiqueta')"
                                    :value="Figures::count($mail->withoutEmail)"
                                    :hint="__('gerencia::tablero.cobro.sin_correo.pista')"
                                />
                                <x-ui.stat
                                    :label="__('gerencia::tablero.cobro.fallidos.etiqueta')"
                                    :value="Figures::count($mail->failed)"
                                    :hint="__('gerencia::tablero.cobro.fallidos.pista')"
                                />
                            </div>

                            @if (count($kindResults->companies) > 1)
                                <x-ui.table :caption="__('gerencia::tablero.cobro.tabla', ['tipo' => $kindLabel])" :caption-hidden="true" :columns="$mailColumns">
                                    @foreach ($kindResults->companies as $row)
                                        <tr>
                                            <th scope="row">{{ $row->company->label() }}</th>
                                            <td data-align="end">{{ Figures::count($row->runs) }}</td>
                                            <td data-align="end">{{ Figures::count($row->sent) }}</td>
                                            <td data-align="end">{{ Figures::count($row->delivered()) }}</td>
                                            <td data-align="end">{{ Figures::count($row->bounced) }}</td>
                                            <td data-align="end">{{ $row->bounceRate() === null ? '—' : Figures::percent($row->bounceRate()) }}</td>
                                            <td data-align="end">{{ Figures::count($row->replies) }}</td>
                                            <td data-align="end">{{ $row->replyRate() === null ? '—' : Figures::percent($row->replyRate()) }}</td>
                                            <td data-align="end">{{ Figures::count($row->withoutEmail) }}</td>
                                            <td data-align="end">{{ Figures::count($row->failed) }}</td>
                                        </tr>
                                    @endforeach
                                </x-ui.table>
                            @endif
                        @endif
                    </section>
                @endforeach

                <x-ui.stat
                    class="max-w-sm"
                    :label="__('gerencia::tablero.cobro.sin_leer.etiqueta')"
                    :value="Figures::count($board->mail->unread)"
                    :hint="__('gerencia::tablero.cobro.sin_leer.pista')"
                />

                @php
                    $canSeeRuns = Route::has('cobro.lotes.index') && auth()->user()?->can('cobro.view');
                    $canSeeReplies = Route::has('cobro.respuestas.index') && auth()->user()?->can('cobro.replies');
                @endphp

                @if ($canSeeRuns || $canSeeReplies)
                    <div class="flex flex-wrap gap-2">
                        @if ($canSeeRuns)
                            <x-ui.link-button variant="secondary" icon="send" :href="route('cobro.lotes.index')">{{ __('gerencia::tablero.cobro.enlaces.envios') }}</x-ui.link-button>
                        @endif
                        @if ($canSeeReplies)
                            <x-ui.link-button variant="secondary" icon="inbox" :href="route('cobro.respuestas.index')">{{ __('gerencia::tablero.cobro.enlaces.respuestas') }}</x-ui.link-button>
                        @endif
                    </div>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card :title="__('gerencia::tablero.recaudo.titulo')" :description="__('gerencia::tablero.recaudo.descripcion', ['granularidad' => $granularity])">
            @if ($collections->isEmpty())
                <x-ui.empty icon="bank" :title="__('gerencia::tablero.recaudo.vacio.titulo')" :description="__('gerencia::tablero.recaudo.vacio.detalle')" />
            @else
                <div class="grid gap-5">
                    <x-ui.stat
                        class="max-w-sm"
                        :label="__('gerencia::tablero.recaudo.total.etiqueta')"
                        :hint="__('gerencia::tablero.recaudo.total.pista')"
                    >
                        <x-ui.money :value="$collections->total()" />
                    </x-ui.stat>

                    <x-gerencia::bar-chart
                        :bars="$collectionsChart['bars']"
                        :label="__('gerencia::tablero.recaudo.grafico', ['granularidad' => $granularity])"
                        :tone="$collectionsTone"
                        format="money"
                    />

                    <x-ui.table :caption="__('gerencia::tablero.recaudo.semanal.tabla')" :caption-hidden="true" :columns="$weeklyColumns">
                        @foreach ($collections->weekly as $week)
                            <tr>
                                <th scope="row">{{ __('gerencia::tablero.recaudo.semanal.rango', ['desde' => DateFormat::date($week->weekStart), 'hasta' => DateFormat::date($week->weekEnd)]) }}</th>
                                @if ($isGroup)
                                    <td><x-ui.chip :dot="false">{{ $week->company->value }}</x-ui.chip></td>
                                @endif
                                <td data-align="end"><x-ui.money :value="$week->total" /></td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    <details class="text-sm">
                        <summary class="inline-flex min-h-touch cursor-pointer items-center font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('gerencia::tablero.recaudo.diario.resumen') }}</summary>
                        <x-ui.table :caption="__('gerencia::tablero.recaudo.diario.tabla')" :caption-hidden="true" :columns="$dailyColumns">
                            @foreach ($collections->dayTotals() as $day)
                                <tr>
                                    <th scope="row"><x-ui.date :value="$day->date" /></th>
                                    <td data-align="end"><x-ui.money :value="$day->total" /></td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </details>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
