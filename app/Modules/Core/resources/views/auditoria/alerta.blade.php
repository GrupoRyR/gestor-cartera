@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Support\Formatting\DateFormat')

{{--
    E-mail of core:verificar-auditoria when the hash chain of the audit
    trail is broken. Only ids, counts and the reason: never the contents of
    an entry. $at: when the check ran (Bogotá); $id: ?int, the first entry
    with a problem; $reason: the key of core::auditoria.motivos; $checked:
    how many entries passed before it.

    Brand v2, inline styles only (mail clients ignore stylesheets and CSS
    variables), in the neutral palette: the alert is about the platform,
    not one company. A 600 px column with the head band behind the white
    title (14.92:1) and an accent border, section titles in ink (17.61:1),
    long text in text (9.17:1) and the neutral grays of the brand tokens.
    No image: the logo is separate work.
--}}
@php($palette = BrandPalette::neutral())
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('core::auditoria.correo.titulo') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">{{ __('core::auditoria.correo.titulo') }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;">{{ __('core::auditoria.correo.intro', ['fecha' => DateFormat::date($at), 'hora' => $at->format('H:i')]) }}</p>

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('core::auditoria.correo.entrada') }}</h2>
                            <p style="margin:0 0 14px;">
                                @if ($id === null)
                                    {{ __('core::auditoria.correo.sin_entrada', ['motivo' => __("core::auditoria.motivos.{$reason}")]) }}
                                @else
                                    {{ __('core::auditoria.correo.entrada_detalle', ['id' => $id, 'motivo' => __("core::auditoria.motivos.{$reason}")]) }}
                                @endif
                            </p>
                            <p style="margin:0 0 14px;">{{ trans_choice('core::auditoria.correo.revisadas', $checked, ['total' => $checked]) }}</p>

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('core::auditoria.correo.que_hacer') }}</h2>
                            <p style="margin:0;">{{ __('core::auditoria.correo.pasos') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('core::auditoria.correo.pie') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
