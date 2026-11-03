@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Modules\Core\Contracts\Enums\CompanyCode')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    E-mail of ApprovalMail when a settlement is approved. $report:
    ConsolidatedReport of the approved version; $month: "julio de 2026";
    $approver: the User who approved; $fileName: the attached PDF;
    $url: the settlement of the period.

    Brand v2, inline styles only (mail clients ignore stylesheets and CSS
    variables), in the Segurex palette: commissions are Segurex's and the
    mail goes out from its mailbox. A 600 px column with the head band
    behind the white title (7.01:1) and an accent border, labels in ink
    (7.10:1), long text in text (7.01:1), the button behind the white link
    (4.51:1) and the neutral grays of the brand tokens. No image: the logo
    is separate work.
--}}
@php($palette = BrandPalette::official(CompanyCode::SGX))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('comisiones::correos.aprobacion.titulo') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">{{ __('comisiones::correos.aprobacion.titulo') }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;">{{ __('comisiones::correos.aprobacion.intro', ['mes' => $month]) }}</p>

                            <ul style="margin:0 0 14px;padding:0 0 0 20px;">
                                <li><strong style="color:{{ $palette->ink }};">{{ __('comisiones::correos.aprobacion.periodo') }}:</strong> {{ $month }} ({{ $report->settlement->period->key() }})</li>
                                <li>{{ __('comisiones::comun.version_revision', ['version' => $report->settlement->version, 'revision' => $report->settlement->revision]) }}</li>
                                <li><strong style="color:{{ $palette->ink }};">{{ __('comisiones::correos.aprobacion.aprobada_por') }}:</strong> {{ $approver->name }} ({{ $approver->email }})</li>
                                <li><strong style="color:{{ $palette->ink }};">{{ __('comisiones::correos.aprobacion.aprobada_el') }}:</strong> {{ DateFormat::dateTime($report->settlement->approvedAt) }}</li>
                                <li><strong style="color:{{ $palette->ink }};">{{ __('comisiones::correos.aprobacion.total_regular') }}:</strong> {{ Money::format($report->regularTotal->total->amount()) }}</li>
                                <li><strong style="color:{{ $palette->ink }};">{{ __('comisiones::correos.aprobacion.total_nomina') }}:</strong> {{ Money::format($report->payrollTotal->amount()) }}</li>
                            </ul>

                            <p style="margin:0 0 20px;">{{ __('comisiones::correos.aprobacion.adjunto', ['archivo' => $fileName]) }}</p>

                            <p style="margin:0;">
                                <a href="{{ $url }}" style="display:inline-block;padding:10px 18px;background:{{ $palette->button }};color:#ffffff;font-weight:bold;text-decoration:none;">{{ __('comisiones::correos.aprobacion.enlace') }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('comisiones::correos.aprobacion.pie') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
