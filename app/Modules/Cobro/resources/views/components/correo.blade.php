{{--
    <x-cobro::correo>: the brand frame of every Cobro mail. Mail clients
    ignore stylesheets and CSS variables, so the frame uses inline styles
    with the company's BrandPalette values (button behind white text, tint
    behind ink text: the AA pairs of ryr-design) and neutral greys; nothing
    else sets a color. $company: Company; $title: the <title>; $draft: shows
    the draft banner; $test: shows the test banner.
--}}
@props(['company', 'title', 'draft' => false, 'test' => false])

@php($palette = $company->palette)
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                    @if ($test)
                        <tr>
                            <td data-banner="prueba" style="padding:10px 24px;background:{{ $palette->tint }};color:{{ $palette->ink }};font-size:13px;font-weight:bold;">
                                {{ __('cobro::correos.prueba') }}
                            </td>
                        </tr>
                    @endif
                    @if ($draft)
                        <tr>
                            <td data-banner="borrador" style="padding:10px 24px;background:{{ $palette->tint }};color:{{ $palette->ink }};font-size:13px;font-weight:bold;">
                                {{ __('cobro::correos.borrador') }}
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->button }};color:#ffffff;font-size:18px;font-weight:bold;border-radius:8px 8px 0 0;">
                            {{ $company->name }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">
                            {{ __('cobro::correos.pie', ['empresa' => $company->name]) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
