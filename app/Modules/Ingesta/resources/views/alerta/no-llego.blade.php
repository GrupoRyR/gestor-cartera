@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Modules\Core\Contracts\Enums\CompanyCode')

{{--
    E-mail of WatchLoadSlot when neither a load nor a final failure report
    arrived for a slot. $names: the subject placeholders (lote, empresa,
    hora, fecha, revision); $explanation: who did not run and what waits
    on the load; $url: the load history, filtered.

    Same layout as alerta/falla.blade.php (brand v2, inline styles only),
    in the Segurex palette: the mail goes out from the Segurex mailbox.
--}}
@php($palette = BrandPalette::official(CompanyCode::SGX))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('ingesta::alerta.no_llego.titulo', $names) }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">{{ __('ingesta::alerta.no_llego.titulo', $names) }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;">{{ __('ingesta::alerta.no_llego.intro', $names) }}</p>

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('ingesta::alerta.no_llego.que_paso') }}</h2>
                            <p style="margin:0 0 20px;">{{ $explanation }}</p>

                            <p style="margin:0;">
                                <a href="{{ $url }}" style="display:inline-block;padding:10px 18px;background:{{ $palette->button }};color:#ffffff;font-weight:bold;text-decoration:none;">{{ __('ingesta::alerta.no_llego.enlace') }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('ingesta::alerta.no_llego.pie') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
