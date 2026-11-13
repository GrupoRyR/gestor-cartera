@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')
@use('Illuminate\Support\Facades\Lang')

{{--
    The account statement of a client, drawn as a PDF by dompdf (see
    ClientStatement). This is a document, not a screen of the app: dompdf
    needs its CSS in the page and cannot use the app components or the
    --brand-* variables, so the colors come from the palette of the client's
    company, passed in as $palette, and nothing here writes a company color.
    Tables only, because dompdf does not lay out flex or grid.

    Brand v2 for documents, without the logo, like the Comisiones PDFs: the
    head band behind the white title over an accent rule, long text in text,
    titles, figures and table headers in ink, light tint tiles and headers,
    secondary lines in gray #6a6b6e (5.33:1), thin gray row lines and the
    Helvetica core font of dompdf (no embedded font file). Contrast, Segurex
    and Derivados: white on head 7.01:1 and 11.5:1; text on white 7.01:1 and
    11.5:1, on tint 6.13:1 and 9.8:1; ink on tint 6.21:1 and 9.8:1.
--}}
@php
    $summary = $detail->summary;
    $aging = $position->aging;
    $buckets = ['not_due' => $aging->notDue, 'days_1_30' => $aging->days1To30, 'days_31_60' => $aging->days31To60, 'days_61_90' => $aging->days61To90, 'over_90' => $aging->over90];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('asesores::exportar.pdf.titulo') }} · {{ $summary->name }}</title>
    <style>
        @page { margin: 30px 34px 40px 34px; }
        body { font-family: Helvetica, Arial, "Helvetica Neue", sans-serif; font-size: 10px; line-height: 1.4; color: {{ $palette->text }}; }
        h1 { margin: 0; font-size: 18px; line-height: 1.25; }
        h2 { margin: 22px 0 6px 0; font-size: 12px; color: {{ $palette->ink }}; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; padding: 5px 6px; color: {{ $palette->ink }}; background: {{ $palette->tint }}; border-bottom: 1px solid {{ $palette->accent }}; }
        td { padding: 5px 6px; border-bottom: 0.5px solid #e3e0dd; vertical-align: top; }
        .end { text-align: right; }
        .muted { color: #6a6b6e; }
        .band td { padding: 12px 14px; background: {{ $palette->head }}; border-bottom: 3px solid {{ $palette->accent }}; }
        .band h1 { color: #ffffff; }
        .name { font-size: 12px; font-weight: bold; color: {{ $palette->ink }}; }
        .tile { padding: 8px 10px; background: {{ $palette->tint }}; border-bottom: 0; border-right: 4px solid #ffffff; }
        .tile .label { font-size: 9px; }
        .tile .value { font-size: 14px; font-weight: bold; color: {{ $palette->ink }}; }
        .meta td, .info td { border: 0; padding: 2px 0; }
        .foot { margin-top: 22px; font-size: 8px; }
    </style>
</head>
<body>
    <table class="band">
        <tr>
            <td><h1>{{ __('asesores::exportar.pdf.titulo') }}</h1></td>
        </tr>
    </table>

    <table class="meta" style="margin-top: 6px;">
        <tr>
            <td><p>{{ $company->name }}</p></td>
            <td class="end">
                <p class="muted">{{ __('asesores::exportar.pdf.generado') }}: {{ DateFormat::dateTime($generatedAt) }}</p>
                @if ($cutoff !== null)
                    <p>{{ __('asesores::exportar.pdf.corte') }}: {{ DateFormat::date($cutoff) }}</p>
                @endif
            </td>
        </tr>
    </table>

    <table class="info" style="margin-top: 12px;">
        <tr>
            <td colspan="2"><span class="name">{{ $summary->name }}</span></td>
        </tr>
        <tr>
            <td>{{ __('asesores::exportar.pdf.nit') }}: {{ $summary->taxId }}</td>
            <td>{{ __('asesores::exportar.pdf.codigo') }}: {{ $summary->code }}</td>
        </tr>
        <tr>
            <td>{{ __('asesores::exportar.pdf.asesor') }}: {{ $summary->advisor?->name ?? __('asesores::comun.sin_datos') }}</td>
            <td>{{ __('asesores::exportar.pdf.condicion') }}: {{ $detail->paymentTerms ?? __('asesores::comun.sin_datos') }}</td>
        </tr>
    </table>

    <h2>{{ __('asesores::exportar.pdf.resumen') }}</h2>
    <table>
        <tr>
            <td class="tile"><span class="label">{{ __('asesores::exportar.pdf.saldo') }}</span><br><span class="value">{{ Money::format($position->balance) }}</span></td>
            <td class="tile"><span class="label">{{ __('asesores::exportar.pdf.sin_vencer') }}</span><br><span class="value">{{ Money::format($position->notDue) }}</span></td>
            <td class="tile"><span class="label">{{ __('asesores::exportar.pdf.vencido') }}</span><br><span class="value">{{ Money::format($position->overdue) }}</span></td>
            <td class="tile"><span class="label">{{ __('asesores::exportar.pdf.mas_90') }}</span><br><span class="value">{{ Money::format($aging->over90) }}</span></td>
        </tr>
    </table>

    <h2>{{ __('asesores::exportar.pdf.edades') }}</h2>
    <table>
        <tr>
            @foreach ($buckets as $key => $amount)
                <th class="end">{{ __('asesores::comun.edades.'.$key) }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($buckets as $amount)
                <td class="end">{{ Money::format($amount) }}</td>
            @endforeach
        </tr>
    </table>

    <h2>{{ __('asesores::exportar.pdf.documentos') }}</h2>
    @if ($invoices === [])
        <p>{{ __('asesores::exportar.pdf.sin_documentos') }}</p>
    @else
        <table>
            <tr>
                <th>{{ __('asesores::exportar.pdf.columnas.documento') }}</th>
                <th>{{ __('asesores::exportar.pdf.columnas.tipo') }}</th>
                <th>{{ __('asesores::exportar.pdf.columnas.emision') }}</th>
                <th>{{ __('asesores::exportar.pdf.columnas.vence') }}</th>
                <th class="end">{{ __('asesores::exportar.pdf.columnas.dias') }}</th>
                <th class="end">{{ __('asesores::exportar.pdf.columnas.valor') }}</th>
                <th class="end">{{ __('asesores::exportar.pdf.columnas.saldo') }}</th>
                <th class="end">{{ __('asesores::exportar.pdf.columnas.valor_antes_iva') }}</th>
                <th class="end">{{ __('asesores::exportar.pdf.columnas.saldo_antes_iva') }}</th>
            </tr>
            @foreach ($invoices as $invoice)
                @php($typeKey = 'asesores::ficha.facturas.tipos.'.$invoice->documentType)
                <tr>
                    <td>{{ $invoice->documentNumber }}</td>
                    <td>{{ Lang::has($typeKey) ? __($typeKey) : $invoice->documentType }}</td>
                    <td>{{ DateFormat::date($invoice->issuedAt) }}</td>
                    <td>{{ DateFormat::date($invoice->dueAt) }}</td>
                    <td class="end">{{ $invoice->daysOverdue > 0 ? $invoice->daysOverdue : __('asesores::comun.sin_datos') }}</td>
                    <td class="end">{{ Money::format($invoice->amount) }}</td>
                    <td class="end"><strong>{{ Money::format($invoice->balance) }}</strong></td>
                    <td class="end">{{ Money::format($invoice->amountBeforeTax) }}</td>
                    <td class="end">{{ Money::format($invoice->balanceBeforeTax) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="foot muted">{{ __('asesores::exportar.pdf.pie', ['empresa' => $company->name, 'fecha' => DateFormat::dateTime($generatedAt)]) }}</p>
</body>
</html>
