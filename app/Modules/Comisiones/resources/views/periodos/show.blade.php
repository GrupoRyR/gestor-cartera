@use('App\Modules\Comisiones\Domain\Calculation\PersonSettlement')
@use('App\Modules\Comisiones\Domain\Settlement\SettlementStatus')
@use('App\Modules\Comisiones\Domain\Shared\Money')
@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Modules\Comisiones\Infrastructure\Settlement\UserNames')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /comisiones/periodos/{periodo} (comisiones.periodos.show): the
    settlement of a period. $selected: SelectedSettlement (the version shown,
    ?version= or the latest); $versions: SettlementRecord rows of the period;
    $names: e-mail => user name; $corrupt: the stored result failed its
    check; $approval: null, or ['reasons' => list<string>] when the user may
    see the approval form (latest draft and comisiones.approve); $mail:
    null, or ['sent' => bool, 'text' => string], the approval mail of the
    version (ApprovalMailLog).
--}}
@php
    $settlement = $selected->settlement;
    $isLatest = $selected->isLatest();
    $title = __('comisiones::liquidacion.titulo', ['mes' => Labels::month($period)]);
    $result = $settlement?->result;
    $blocking = $result === null ? [] : $result->blockingAlerts();
    $warnings = $result === null ? 0 : count($result->alerts()) - count($blocking);
    $payroll = $result === null ? null : Money::sum(array_map(fn (PersonSettlement $person) => $person->total, $result->persons()));
    $alertColumns = [
        ['key' => 'tipo', 'label' => __('comisiones::liquidacion.alertas.columnas.tipo')],
        ['key' => 'severidad', 'label' => __('comisiones::liquidacion.alertas.columnas.severidad')],
        ['key' => 'mensaje', 'label' => __('comisiones::liquidacion.alertas.columnas.mensaje')],
        ['key' => 'codigo', 'label' => __('comisiones::liquidacion.alertas.columnas.codigo'), 'align' => 'end'],
    ];

    if ($settlement !== null && $settlement->justifications !== []) {
        $alertColumns[] = ['key' => 'justificacion', 'label' => __('comisiones::liquidacion.alertas.columnas.justificacion')];
    }

    $versionColumns = [
        ['key' => 'version', 'label' => __('comisiones::liquidacion.versiones.columnas.version')],
        ['key' => 'estado', 'label' => __('comisiones::liquidacion.versiones.columnas.estado')],
        ['key' => 'revision', 'label' => __('comisiones::liquidacion.versiones.columnas.revision'), 'align' => 'end'],
        ['key' => 'calculada', 'label' => __('comisiones::liquidacion.versiones.columnas.calculada')],
        ['key' => 'motivo', 'label' => __('comisiones::liquidacion.versiones.columnas.motivo')],
    ];
    $canCalculate = auth()->user()?->can('comisiones.calculate') ?? false;
    $canClose = auth()->user()?->can('comisiones.close') ?? false;
@endphp

<x-layouts.app :title="$title">
    <x-comisiones::period-header :period="$period" :title="$title" current="resumen" :version="$selected->versionQuery()">
        @if ($settlement !== null && $isLatest)
            <x-slot:actions>
                @if ($settlement->isOpen() && $canCalculate)
                    <form method="POST" action="{{ route('comisiones.periodos.calcular', $period->key()) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="refresh">{{ __('comisiones::liquidacion.acciones.recalcular') }}</x-ui.button>
                    </form>
                @endif

                @if ($settlement->status === SettlementStatus::Approved && $canClose)
                    <form method="POST" action="{{ route('comisiones.periodos.cerrar', $period->key()) }}">
                        @csrf
                        <x-ui.button type="submit" icon="check">{{ __('comisiones::liquidacion.acciones.cerrar') }}</x-ui.button>
                    </form>
                @endif

                @if ($settlement->status === SettlementStatus::Closed && $canCalculate)
                    <x-ui.button variant="secondary" icon="plus" data-modal-open="complementaria">{{ __('comisiones::liquidacion.acciones.complementaria') }}</x-ui.button>
                @endif
            </x-slot:actions>
        @endif
    </x-comisiones::period-header>

    <div class="flex flex-col gap-6">
        @if ($corrupt)
            <x-ui.alert tone="danger">{{ __('comisiones::liquidacion.errores.corrupta') }}</x-ui.alert>
        @endif

        @if (session('razones'))
            <x-ui.alert tone="warning" :title="__('comisiones::liquidacion.aprobacion.no_puedes')">
                <ul class="list-disc ps-5">
                    @foreach ((array) session('razones') as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($settlement === null)
            @unless ($corrupt)
                <x-ui.empty icon="document"
                    :title="__('comisiones::liquidacion.vacio.titulo', ['mes' => Labels::month($period)])"
                    :description="$canCalculate ? __('comisiones::liquidacion.vacio.descripcion') : __('comisiones::liquidacion.vacio.descripcion_consulta')">
                    @if ($canCalculate)
                        <form method="POST" action="{{ route('comisiones.periodos.calcular', $period->key()) }}">
                            @csrf
                            <x-ui.button type="submit" icon="refresh">{{ __('comisiones::liquidacion.acciones.calcular') }}</x-ui.button>
                        </form>
                    @endif
                </x-ui.empty>
            @endunless
        @else
            @unless ($isLatest)
                <x-ui.alert tone="info">
                    {{ __('comisiones::comun.viendo_version', ['version' => $settlement->version, 'vigente' => $selected->latestVersion]) }}
                    <a href="{{ route('comisiones.periodos.show', $period->key()) }}" class="font-semibold underline underline-offset-4">{{ __('comisiones::comun.ver_vigente') }}</a>
                </x-ui.alert>
            @endunless

            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
                <x-ui.stat :label="__('comisiones::liquidacion.resumen.estado')" :hint="__('comisiones::comun.version_revision', ['version' => $settlement->version, 'revision' => $settlement->revision])">
                    <x-ui.chip :tone="Labels::statusTone($settlement->status)" data-status="{{ $settlement->status->value }}">{{ Labels::status($settlement->status) }}</x-ui.chip>
                </x-ui.stat>
                <x-ui.stat :label="__('comisiones::liquidacion.resumen.total_regular')" :hint="__('comisiones::liquidacion.resumen.total_regular_ayuda')">
                    <x-ui.money :value="$result->regularTotal()->amount()" />
                </x-ui.stat>
                <x-ui.stat :label="__('comisiones::liquidacion.resumen.total_nomina')" :hint="__('comisiones::liquidacion.resumen.total_nomina_ayuda')">
                    <x-ui.money :value="$payroll->amount()" />
                </x-ui.stat>
                <x-ui.stat :label="__('comisiones::liquidacion.resumen.alertas')"
                    :value="(string) count($blocking)"
                    :hint="__('comisiones::liquidacion.resumen.alertas_ayuda', ['advertencias' => $warnings])"
                    :tone="count($blocking) > 0 && $settlement->status === SettlementStatus::Draft ? 'danger' : 'neutral'" />
            </div>

            <x-ui.card :title="__('comisiones::liquidacion.trazabilidad.titulo')">
                <dl class="grid gap-x-6 gap-y-3 text-[0.9375rem] sm:grid-cols-3">
                    @foreach ([
                        'calculada' => [$settlement->calculatedAt, $settlement->calculatedBy],
                        'aprobada' => [$settlement->approvedAt, $settlement->approvedBy],
                        'cerrada' => [$settlement->closedAt, $settlement->closedBy],
                    ] as $step => [$at, $by])
                        <div data-step="{{ $step }}">
                            <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::liquidacion.trazabilidad.'.$step) }}</dt>
                            <dd>
                                @if ($at === null)
                                    {{ __('comisiones::liquidacion.trazabilidad.pendiente') }}
                                @else
                                    {{ __('comisiones::liquidacion.trazabilidad.por', ['fecha' => DateFormat::dateTime($at), 'nombre' => UserNames::name($names, $by)]) }}
                                @endif
                            </dd>
                        </div>
                    @endforeach

                    @if ($settlement->reason !== null)
                        <div class="sm:col-span-3">
                            <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::liquidacion.trazabilidad.motivo') }}</dt>
                            <dd>{{ $settlement->reason }} · {{ __('comisiones::liquidacion.trazabilidad.corrige', ['version' => $settlement->previousVersion]) }}</dd>
                        </div>
                    @endif

                    @if ($mail !== null && $mail['sent'])
                        <div class="sm:col-span-3" data-mail="enviado">
                            <dt class="text-[0.8125rem] font-semibold text-fg-muted">{{ __('comisiones::liquidacion.correo.titulo') }}</dt>
                            <dd>{{ $mail['text'] }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($mail !== null && ! $mail['sent'])
                    <x-ui.alert tone="warning" class="mt-4" data-mail="fallido">{{ $mail['text'] }}</x-ui.alert>
                @endif
            </x-ui.card>

            @if ($approval !== null)
                <x-ui.card id="aprobacion" :title="__('comisiones::liquidacion.aprobacion.titulo')" :description="__('comisiones::liquidacion.aprobacion.descripcion', ['revision' => $settlement->revision])">
                    <form method="POST" action="{{ route('comisiones.periodos.aprobar', $period->key()) }}" class="flex flex-col gap-4">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $settlement->revision }}">

                        @if ($approval['reasons'] !== [])
                            <x-ui.alert tone="warning" :title="__('comisiones::liquidacion.aprobacion.no_puedes')">
                                <ul class="list-disc ps-5">
                                    @foreach ($approval['reasons'] as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </x-ui.alert>
                        @endif

                        @if ($blocking === [])
                            <p class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::liquidacion.aprobacion.sin_bloqueantes') }}</p>
                        @else
                            <p class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::liquidacion.aprobacion.justificar') }}</p>

                            @foreach ($blocking as $index => $alert)
                                <input type="hidden" name="justificaciones[{{ $index }}][clave]" value="{{ $alert->key() }}">
                                <x-ui.textarea
                                    name="justificaciones[{{ $index }}][texto]"
                                    :label="__('comisiones::liquidacion.aprobacion.justificacion_de', ['alerta' => $alert->kind->label().': '.$alert->reference])"
                                    :hint="$alert->message"
                                    :rows="2"
                                    :required="true"
                                    data-alert="{{ $alert->key() }}" />
                            @endforeach
                        @endif

                        <div>
                            <x-ui.button type="submit" icon="check" :disabled="$approval['reasons'] !== []">{{ __('comisiones::liquidacion.acciones.aprobar') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card :title="__('comisiones::liquidacion.alertas.titulo')">
                <x-ui.table :caption="__('comisiones::liquidacion.alertas.tabla')" :caption-hidden="true" :columns="$alertColumns" :empty-message="__('comisiones::liquidacion.alertas.vacio')">
                    @foreach ($result->alerts() as $alert)
                        <tr data-alert="{{ $alert->key() }}">
                            <td class="min-w-40">{{ $alert->kind->label() }}</td>
                            <td><x-ui.chip :tone="$alert->isBlocking() ? 'danger' : 'warning'">{{ $alert->severity()->label() }}</x-ui.chip></td>
                            <td class="min-w-64 [overflow-wrap:anywhere]">{{ $alert->message }}</td>
                            <td data-align="end">{{ $alert->sellerCode ?? '—' }}</td>
                            @if ($settlement->justifications !== [])
                                <td class="min-w-48 [overflow-wrap:anywhere]">{{ $settlement->justifications[$alert->key()] ?? '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card :title="__('comisiones::liquidacion.versiones.titulo')">
                <x-ui.table :caption="__('comisiones::liquidacion.versiones.tabla')" :caption-hidden="true" :columns="$versionColumns">
                    @foreach ($versions as $row)
                        <tr data-version="{{ $row->version }}">
                            <td>
                                @if ($row->version === $settlement->version)
                                    <span class="font-semibold">{{ __('comisiones::comun.version', ['version' => $row->version]) }}</span>
                                    <span class="text-sm text-fg-muted">· {{ __('comisiones::liquidacion.versiones.actual') }}</span>
                                @else
                                    <a href="{{ route('comisiones.periodos.show', ['periodo' => $period->key(), 'version' => $row->version]) }}" class="font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('comisiones::liquidacion.versiones.ver', ['version' => $row->version]) }}</a>
                                @endif
                            </td>
                            <td><x-ui.chip :tone="Labels::statusTone(SettlementStatus::from($row->estado))">{{ Labels::status(SettlementStatus::from($row->estado)) }}</x-ui.chip></td>
                            <td data-align="end">{{ $row->revision }}</td>
                            <td>{{ __('comisiones::liquidacion.trazabilidad.por', ['fecha' => DateFormat::dateTime($row->calculado_en), 'nombre' => UserNames::name($names, $row->calculado_por)]) }}</td>
                            <td class="min-w-48 [overflow-wrap:anywhere]">{{ $row->motivo ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            @if ($isLatest && $settlement->status === SettlementStatus::Closed && $canCalculate)
                <x-ui.modal id="complementaria" :title="__('comisiones::liquidacion.acciones.complementaria')" :open="$errors->has('motivo')">
                    <form id="form-complementaria" method="POST" action="{{ route('comisiones.periodos.complementaria', $period->key()) }}" class="flex flex-col gap-4">
                        @csrf
                        <p class="text-[0.9375rem] text-fg-soft">{{ __('comisiones::liquidacion.acciones.complementaria_ayuda') }}</p>
                        <x-ui.textarea name="motivo" :label="__('comisiones::liquidacion.acciones.motivo')" :hint="__('comisiones::liquidacion.acciones.motivo_ayuda')" :required="true" :rows="3" />
                    </form>

                    <x-slot:footer>
                        <x-ui.button variant="ghost" data-modal-close>{{ __('comisiones::liquidacion.acciones.cancelar') }}</x-ui.button>
                        <x-ui.button type="submit" form="form-complementaria">{{ __('comisiones::liquidacion.acciones.complementaria_registrar') }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
        @endif
    </div>
</x-layouts.app>
