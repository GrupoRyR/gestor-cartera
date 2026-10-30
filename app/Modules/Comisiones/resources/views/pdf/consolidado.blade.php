@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    The consolidated report (§8.1) as a letter landscape PDF, drawn by dompdf
    (ConsolidatedController::pdf). This is a document, not a screen: dompdf
    needs its CSS in the page and cannot use the app components or the
    --brand-* variables, so the colors come from the SGX palette passed in as
    $palette and nothing here writes a color of its own. Tables only, because
    dompdf does not lay out flex or grid; table headers repeat on every page.

    $report: ConsolidatedReport; $palette: BrandPalette; $generatedAt; $generatedBy.
--}}
@php
    $settlement = $report->settlement;
    $money = fn ($amount): string => Money::format($amount->amount());
    $regularColumns = ['codigo', 'vendedor', 'venta', 'recaudo', 'obr', 'reintegros', 'descuentos', ...($report->hasDirectorColumn ? ['director'] : []), 'total'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 28px 30px 36px 30px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; color: {{ $palette->ink }}; }
        .bar { height: 6px; background: {{ $palette->accent }}; }
        h1 { margin: 12px 0 2px 0; font-size: 15px; }
        h2 { margin: 18px 0 6px 0; font-size: 11px; }
        h3 { margin: 12px 0 4px 0; font-size: 10px; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { text-align: left; font-size: 8px; padding: 4px 5px; background: {{ $palette->tint }}; border-bottom: 1px solid {{ $palette->accent }}; }
        td { padding: 4px 5px; border-bottom: 1px solid {{ $palette->tint }}; vertical-align: top; }
        tfoot td, tfoot th { font-weight: bold; background: {{ $palette->tint }}; border-bottom: 0; }
        .end { text-align: right; }
        .muted { opacity: 0.75; }
        .head td { border: 0; padding: 0; }
        .team { margin-top: 8px; }
    </style>
</head>
<body>
    <div class="bar"></div>

    <table class="head">
        <tr>
            <td>
                <h1>{{ $report->title }}</h1>
                <p>{{ $report->versionLine() }}</p>
            </td>
            <td class="end">
                <p class="muted">{{ __('comisiones::consolidado.generado', ['fecha' => DateFormat::dateTime($generatedAt), 'nombre' => $generatedBy ?? '—']) }}</p>
            </td>
        </tr>
    </table>

    <h2>{{ __('comisiones::consolidado.regular.titulo') }}</h2>
    <table>
        <thead>
            <tr>
                @foreach ($regularColumns as $column)
                    <th @class(['end' => ! in_array($column, ['codigo', 'vendedor'], true)])>{{ __('comisiones::consolidado.regular.columnas.'.$column) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report->regular as $row)
                <tr>
                    <td>{{ $row->reference }}</td>
                    <td>{{ $row->name }}</td>
                    <td class="end">{{ $money($row->saleCommission) }}</td>
                    <td class="end">{{ $money($row->collectionCommission) }}</td>
                    <td class="end">{{ $money($row->obrCommission) }}</td>
                    <td class="end">{{ $money($row->reimbursements) }}</td>
                    <td class="end">{{ $money($row->deductions) }}</td>
                    @if ($report->hasDirectorColumn)
                        <td class="end">{{ $money($row->director) }}</td>
                    @endif
                    <td class="end">{{ $money($row->total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            @php($total = $report->regularTotal)
            <tr>
                <td colspan="2">{{ $total->name }}</td>
                <td class="end">{{ $money($total->saleCommission) }}</td>
                <td class="end">{{ $money($total->collectionCommission) }}</td>
                <td class="end">{{ $money($total->obrCommission) }}</td>
                <td class="end">{{ $money($total->reimbursements) }}</td>
                <td class="end">{{ $money($total->deductions) }}</td>
                @if ($report->hasDirectorColumn)
                    <td class="end">{{ $money($total->director) }}</td>
                @endif
                <td class="end">{{ $money($total->total) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>{{ __('comisiones::consolidado.especiales.titulo') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('comisiones::consolidado.especiales.columnas.codigo') }}</th>
                <th>{{ __('comisiones::consolidado.especiales.columnas.nombre') }}</th>
                <th class="end">{{ __('comisiones::consolidado.especiales.columnas.venta') }}</th>
                <th class="end">{{ __('comisiones::consolidado.especiales.columnas.comision') }}</th>
                <th class="end">{{ __('comisiones::consolidado.especiales.columnas.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->separate as $code)
                <tr>
                    <td>{{ $code->code() }}</td>
                    <td>{{ $code->rule->name }}</td>
                    <td class="end">{{ $money($code->sales) }}</td>
                    <td class="end">{{ $money($code->saleCommission) }}</td>
                    <td class="end">{{ $money($code->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ __('comisiones::consolidado.directores.titulo') }}</h2>
    @foreach ($report->directors as $director)
        @php($team = $director->team)
        <div class="team">
            <h3>{{ __('comisiones::consolidado.directores.equipo', ['canal' => $team->channel, 'director' => $team->director->name]) }}</h3>
            <p class="muted">
                {{ __('comisiones::consolidado.directores.tasa', ['tasa' => Labels::rate($team->rate)]) }}
                @if ($director->paidWithCode !== null)
                    {{ __('comisiones::consolidado.directores.con_codigo', ['codigo' => $director->paidWithCode]) }}
                @endif
            </p>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('comisiones::consolidado.directores.columnas.codigo') }}</th>
                        <th>{{ __('comisiones::consolidado.directores.columnas.nombre') }}</th>
                        <th class="end">{{ __('comisiones::consolidado.directores.columnas.recaudo') }}</th>
                        <th class="end">{{ __('comisiones::consolidado.directores.columnas.venta') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($director->rows as $member)
                        <tr>
                            <td>{{ $member->code }}</td>
                            <td>{{ $member->name }}</td>
                            <td class="end">{{ $money($member->collected) }}</td>
                            <td class="end">{{ $money($member->sales) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">{{ __('comisiones::consolidado.directores.total') }}</td>
                        <td class="end">{{ $money($director->collected) }}</td>
                        <td class="end">{{ $money($director->sales) }}</td>
                    </tr>
                    <tr>
                        <td colspan="2">{{ __('comisiones::consolidado.directores.comision') }}</td>
                        <td class="end">{{ $money($director->collectionCommission) }}</td>
                        <td class="end">{{ $money($director->salesCommission) }}</td>
                    </tr>
                    <tr>
                        <td colspan="3">{{ __('comisiones::consolidado.directores.nomina') }}</td>
                        <td class="end">{{ $money($director->total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endforeach

    <h2>{{ __('comisiones::consolidado.personas.titulo') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('comisiones::consolidado.personas.columnas.persona') }}</th>
                <th>{{ __('comisiones::consolidado.personas.columnas.roles') }}</th>
                <th class="end">{{ __('comisiones::consolidado.personas.columnas.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->persons as $person)
                <tr>
                    <td>{{ $person->person->name }}</td>
                    <td>
                        @foreach ($person->roles as $role)
                            {{ $role->label }}: {{ $money($role->amount) }}@if (! $loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td class="end">{{ $money($person->total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('comisiones::consolidado.personas.total') }}</td>
                <td class="end">{{ $money($report->payrollTotal) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
