@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    The collection report of one seller (§8.2) as a letter landscape PDF,
    drawn by dompdf (ReportArchive::collection). Like pdf/consolidado, a
    document and not a screen: its CSS lives in the page, its colors come
    from the SGX palette passed in as $palette, and it is laid out with
    tables only. The table header repeats on every page (thead as
    table-header-group).

    Payments and crossings share one table in payment date order; crossings
    carry «(cruce)». Line commissions are exact and shown rounded to the
    peso; the total row prints the report total, which equals the
    consolidated figure (§9.3). A derived report (85.1) adds its note and
    shows «—» for the days.

    $report: CollectionReport; $palette: BrandPalette; $generatedAt.
--}}
@php
    $money = fn ($amount): string => Money::format($amount->amount());
    $columns = ['vendedor', 'codigo', 'factura', 'codigo_cliente', 'razon_social', 'fecha_factura', 'fecha_pago', 'importe_original', 'dias', 'valor_pagado', 'comision'];
    $endColumns = ['importe_original', 'dias', 'valor_pagado', 'comision'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('comisiones::informes.pdf.titulo') }} · {{ $report->seller }}</title>
    <style>
        @page { margin: 28px 30px 36px 30px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; line-height: 1.35; color: {{ $palette->ink }}; }
        .bar { height: 6px; background: {{ $palette->primary }}; }
        h1 { margin: 12px 0 2px 0; font-size: 15px; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { text-align: left; font-size: 7.5px; padding: 4px 4px; background: {{ $palette->tint }}; border-bottom: 1px solid {{ $palette->primary }}; }
        td { padding: 3px 4px; border-bottom: 1px solid {{ $palette->tint }}; vertical-align: top; }
        tfoot td { font-weight: bold; background: {{ $palette->tint }}; border-bottom: 0; }
        .end { text-align: right; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .muted { opacity: 0.75; }
        .head td { border: 0; padding: 0; }
        .note { margin: 8px 0 0 0; padding: 6px 8px; background: {{ $palette->tint }}; }
        .lines { margin-top: 10px; }
        .count { margin-top: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="bar"></div>

    <table class="head">
        <tr>
            <td>
                <h1>{{ __('comisiones::informes.pdf.titulo') }}</h1>
                <p>
                    {{ __('comisiones::informes.pdf.comercial', ['nombre' => $report->seller]) }}
                    · {{ __('comisiones::informes.pdf.periodo', ['mes' => Labels::month($report->period)]) }}
                    · {{ __('comisiones::informes.pdf.generado', ['fecha' => DateFormat::dateTime($generatedAt)]) }}
                </p>
            </td>
            <td class="end">
                <p class="muted">{{ __('comisiones::informes.pdf.version', ['version' => $report->version]) }}</p>
            </td>
        </tr>
    </table>

    @if ($report->note !== null)
        <p class="note">{{ $report->note }}</p>
    @endif

    <table class="lines">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th @class(['end' => in_array($column, $endColumns, true)])>{{ __('comisiones::informes.pdf.columnas.'.$column) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report->lines as $line)
                <tr>
                    <td>{{ $report->lineSeller }}</td>
                    <td>{{ $line->sellerCode }}</td>
                    <td>{{ $line->invoiceNumber }}</td>
                    <td>{{ $line->clientCode }}</td>
                    <td>{{ $line->clientLabel() }}</td>
                    <td class="nowrap">{{ Labels::day($line->invoiceDate) }}</td>
                    <td class="nowrap">{{ Labels::day($line->paymentDate) }}</td>
                    <td class="end">{{ $money($line->invoiceAmount) }}</td>
                    <td class="end">{{ $report->showsDays ? $line->days : '—' }}</td>
                    <td class="end">{{ $money($line->paidBase) }}</td>
                    <td class="end">{{ $money($line->commission) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7">{{ __('comisiones::informes.pdf.total') }}</td>
                <td class="end">{{ $money($report->invoiceTotal()) }}</td>
                <td></td>
                <td class="end">{{ $money($report->paidTotal()) }}</td>
                <td class="end">{{ $money($report->total()) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="count">{{ __('comisiones::informes.pdf.transacciones', ['cantidad' => $report->transactions()]) }}</p>
</body>
</html>
