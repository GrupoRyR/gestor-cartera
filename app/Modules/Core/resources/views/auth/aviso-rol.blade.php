@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Support\Formatting\DateFormat')

{{--
    E-mail of SendAdvisorRoleNotice when a sign-in gives the "asesor" role
    on its own (audit S-17). $user: the User; $at: when they signed in
    (Bogotá); $companies: list<string>, the company names the role covers;
    $advisors: list<string>, the linked SAP advisors as "company code".

    Brand v2, inline styles only (mail clients ignore stylesheets and CSS
    variables), in the neutral palette: the role can cover both companies
    and the brand never joins them. A 600 px column with the head band
    behind the white title (14.92:1) and an accent border, section titles
    in ink (17.61:1), long text in text (9.17:1), the button behind the
    white link (17.61:1) and the neutral grays of the brand tokens. No
    image: the logo is separate work.
--}}
@php($palette = BrandPalette::neutral())
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('core::ingreso.aviso_rol.titulo') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">{{ __('core::ingreso.aviso_rol.titulo') }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;">{{ __('core::ingreso.aviso_rol.intro') }}</p>

                            <p style="margin:0 0 14px;">
                                {{ __('core::ingreso.aviso_rol.usuario', ['nombre' => $user->name, 'correo' => $user->email]) }}<br>
                                {{ __('core::ingreso.aviso_rol.cuando', ['fecha' => DateFormat::date($at), 'hora' => $at->format('H:i')]) }}<br>
                                {{ __('core::ingreso.aviso_rol.empresas', ['empresas' => implode(', ', $companies)]) }}<br>
                                {{ __('core::ingreso.aviso_rol.asesores', ['asesores' => implode(', ', $advisors)]) }}
                            </p>

                            <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __('core::ingreso.aviso_rol.que_hacer') }}</h2>
                            <p style="margin:0 0 20px;">{{ __('core::ingreso.aviso_rol.pasos') }}</p>
                            <p style="margin:0;">
                                <a href="{{ route('core.equipo.edit', ['user' => $user->id]) }}" style="display:inline-block;padding:10px 18px;background:{{ $palette->button }};color:#ffffff;font-weight:bold;text-decoration:none;">{{ __('core::ingreso.aviso_rol.enlace') }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('core::ingreso.aviso_rol.pie') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
