@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    E-mail of ApprovalMail when a settlement is approved. Plain HTML
    without styles, so any mail client shows it. $report:
    ConsolidatedReport of the approved version; $month: "julio de 2026";
    $approver: the User who approved; $fileName: the attached PDF;
    $url: the settlement of the period.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('comisiones::correos.aprobacion.titulo') }}</title>
</head>
<body>
    <h1>{{ __('comisiones::correos.aprobacion.titulo') }}</h1>

    <p>{{ __('comisiones::correos.aprobacion.intro', ['mes' => $month]) }}</p>

    <ul>
        <li>{{ __('comisiones::correos.aprobacion.periodo') }}: {{ $month }} ({{ $report->settlement->period->key() }})</li>
        <li>{{ __('comisiones::comun.version_revision', ['version' => $report->settlement->version, 'revision' => $report->settlement->revision]) }}</li>
        <li>{{ __('comisiones::correos.aprobacion.aprobada_por') }}: {{ $approver->name }} ({{ $approver->email }})</li>
        <li>{{ __('comisiones::correos.aprobacion.aprobada_el') }}: {{ DateFormat::dateTime($report->settlement->approvedAt) }}</li>
        <li>{{ __('comisiones::correos.aprobacion.total_regular') }}: {{ Money::format($report->regularTotal->total->amount()) }}</li>
        <li>{{ __('comisiones::correos.aprobacion.total_nomina') }}: {{ Money::format($report->payrollTotal->amount()) }}</li>
    </ul>

    <p>{{ __('comisiones::correos.aprobacion.adjunto', ['archivo' => $fileName]) }}</p>

    <p><a href="{{ $url }}">{{ __('comisiones::correos.aprobacion.enlace') }}</a></p>

    <p>{{ __('comisiones::correos.aprobacion.pie') }}</p>
</body>
</html>
