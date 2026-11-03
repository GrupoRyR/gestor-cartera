@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Support\Formatting\DateFormat')

{{--
    E-mail of ingesta:verificar-carga when a company's robot cartera is
    missing at the deadline. $company: CompanyCode; $deadline: today's
    deadline; $latest: ?LoadRecord, the current accepted cartera;
    $rejected: ?IngestaLoad, today's latest rejected robot cartera;
    $manual: whether a manual cartera with today's cutoff exists; $url:
    the load history.

    Brand v2, inline styles only (mail clients ignore stylesheets and CSS
    variables), in the palette of the company whose load is missing: a
    600 px column with the head band behind the white title (7.01:1
    Segurex, 11.50:1 SDC) and an accent border, section titles in ink,
    long text in text, the button behind the white link (4.51:1 Segurex,
    6.57:1 SDC) and the neutral grays of the brand tokens. No image: the
    logo is separate work.
--}}
@php($palette = BrandPalette::official($company))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('ingesta::alerta.correo.titulo', ['empresa' => $company->label()]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">{{ __('ingesta::alerta.correo.titulo', ['empresa' => $company->label()]) }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;">{{ __('ingesta::alerta.correo.intro', ['hora' => $deadline->format('H:i'), 'fecha' => DateFormat::date($deadline), 'empresa' => $company->label()]) }}</p>

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('ingesta::alerta.correo.ultima') }}</h2>
                            <p style="margin:0 0 14px;">
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

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('ingesta::alerta.correo.rechazo') }}</h2>
                            <p style="margin:0 0 14px;">
                                @if ($rejected === null)
                                    {{ __('ingesta::alerta.correo.sin_rechazo') }}
                                @else
                                    {{ __('ingesta::alerta.correo.rechazo_detalle', ['hora' => $rejected->received_at->setTimezone('America/Bogota')->format('H:i'), 'error' => $rejected->error]) }}
                                @endif
                            </p>

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('ingesta::alerta.correo.manual') }}</h2>
                            <p style="margin:0 0 20px;">{{ $manual ? __('ingesta::alerta.correo.con_manual') : __('ingesta::alerta.correo.sin_manual') }}</p>

                            <p style="margin:0;">
                                <a href="{{ $url }}" style="display:inline-block;padding:10px 18px;background:{{ $palette->button }};color:#ffffff;font-weight:bold;text-decoration:none;">{{ __('ingesta::alerta.correo.enlace') }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('ingesta::alerta.correo.pie') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
