@use('App\Support\Formatting\DateFormat')

{{--
    E-mail of ingesta:verificar-carga when a company's robot cartera is
    missing at the deadline. Plain HTML without styles, so any mail client
    shows it. $company: CompanyCode; $deadline: today's deadline;
    $latest: ?LoadRecord, the current accepted cartera; $rejected:
    ?IngestaLoad, today's latest rejected robot cartera; $manual: whether
    a manual cartera with today's cutoff exists; $url: the load history.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('ingesta::alerta.correo.titulo', ['empresa' => $company->label()]) }}</title>
</head>
<body>
    <h1>{{ __('ingesta::alerta.correo.titulo', ['empresa' => $company->label()]) }}</h1>

    <p>{{ __('ingesta::alerta.correo.intro', ['hora' => $deadline->format('H:i'), 'fecha' => DateFormat::date($deadline), 'empresa' => $company->label()]) }}</p>

    <h2>{{ __('ingesta::alerta.correo.ultima') }}</h2>
    <p>
        @if ($latest === null)
            {{ __('ingesta::alerta.correo.sin_ultima') }}
        @else
            {{ __('ingesta::alerta.correo.ultima_detalle', [
                'corte' => DateFormat::date($latest->cutoffDate),
                'origen' => mb_strtolower($latest->source->label()),
                'recibida' => DateFormat::dateTime($latest->receivedAt),
            ]) }}
        @endif
    </p>

    <h2>{{ __('ingesta::alerta.correo.rechazo') }}</h2>
    <p>
        @if ($rejected === null)
            {{ __('ingesta::alerta.correo.sin_rechazo') }}
        @else
            {{ __('ingesta::alerta.correo.rechazo_detalle', ['hora' => $rejected->received_at->setTimezone('America/Bogota')->format('H:i'), 'error' => $rejected->error]) }}
        @endif
    </p>

    <h2>{{ __('ingesta::alerta.correo.manual') }}</h2>
    <p>{{ $manual ? __('ingesta::alerta.correo.con_manual') : __('ingesta::alerta.correo.sin_manual') }}</p>

    <p><a href="{{ $url }}">{{ __('ingesta::alerta.correo.enlace') }}</a></p>

    <p>{{ __('ingesta::alerta.correo.pie') }}</p>
</body>
</html>
