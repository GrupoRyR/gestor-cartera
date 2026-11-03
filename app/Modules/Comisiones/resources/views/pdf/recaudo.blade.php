@use('App\Modules\Comisiones\Infrastructure\Report\Labels')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    The collection report of one seller (§8.2) as a letter landscape PDF,
    drawn by dompdf (ReportArchive::collection). Like pdf/consolidado, a
    document and not a screen: its CSS lives in the page, its company
    colors come from the SGX palette passed in as $palette, and it is laid
    out with tables only. The table header repeats on every page (thead as
    table-header-group).

    Brand v2 for reports, without the logo, as pdf/consolidado: the head
    band behind the white title (7.01:1) over an accent rule, the seller,
    period and generation line in text (7.01:1), the version in gray
    #6a6b6e (5.33:1), long text in text, light tint table headers, the note
    and the totals in ink (6.21:1 on tint, 7.10:1 on white), thin gray
    lines and the Helvetica core font of dompdf (no embedded font file).

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
        body { font-family: Helvetica, Arial, "Helvetica Neue", sans-serif; font-size: 8.5px; line-height: 1.35; color: {{ $palette->text }}; }
        h1 { margin: 0; font-size: 16px; line-height: 1.25; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { text-align: left; font-size: 7.5px; padding: 4px 4px; color: {{ $palette->ink }}; background: {{ $palette->tint }}; border-bottom: 1px solid {{ $palette->accent }}; }
        td { padding: 3px 4px; border-bottom: 0.5px solid #e3e0dd; vertical-align: top; }
        tfoot td { font-weight: bold; color: {{ $palette->ink }}; background: {{ $palette->tint }}; border-top: 1px solid {{ $palette->ink }}; border-bottom: 0; }
        .end { text-align: right; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .muted { color: #6a6b6e; }
        .band td { padding: 12px 14px; background: {{ $palette->head }}; border-bottom: 3px solid {{ $palette->accent }}; }
        .band h1 { color: #ffffff; }
        .meta { margin-top: 6px; }
        .meta td { border: 0; padding: 0; }
        .note { margin: 8px 0 0 0; padding: 6px 8px; background: {{ $palette->tint }}; color: {{ $palette->ink }}; border-left: 3px solid {{ $palette->accent }}; }
        .lines { margin-top: 10px; }
        .count { margin-top: 6px; font-weight: bold; color: {{ $palette->ink }}; }
    </style>
</head>
<body>
    <table class="band">
        <tr>
            <td><h1>{{ __('comisiones::informes.pdf.titulo') }}</h1></td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td>
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
