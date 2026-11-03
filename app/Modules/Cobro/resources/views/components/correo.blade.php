{{--
    <x-cobro::correo>: the frame of every Cobro mail, on brand v2 (skill
    frontend-marca-sgx-sydc-v2, references/documentos.md). Mail clients
    ignore stylesheets and CSS variables, so it uses inline styles: the
    Helvetica stack, the company's BrandPalette (text for long text, head
    behind white text, accent only as a border, tint behind ink text) and
    the neutral grays of the brand tokens (#f5f4f3 page, #e3e0dd lines,
    #6a6b6e secondary text: 5.33:1 on white). No image: the logo is
    separate work. $company: Company; $title: the <title>; $draft: shows
    the draft banner; $test: shows the test banner; $framed: false drops the
    header band and the footer, for mails that copy a company's own format
    (the weekly collection reads like the mails the team writes in Outlook).
--}}
@props(['company', 'title', 'draft' => false, 'test' => false, 'framed' => true])

@php($palette = $company->palette)
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
@if (! $framed)
<body style="margin:0;padding:16px;background:#ffffff;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:14px;line-height:1.45;color:{{ $palette->text }};">
    @if ($test)
        <p data-banner="prueba" style="margin:0 0 16px;padding:10px 12px;background:{{ $palette->tint }};color:{{ $palette->ink }};font-size:13px;font-weight:bold;">{{ __('cobro::correos.prueba') }}</p>
    @endif
    @if ($draft)
        <p data-banner="borrador" style="margin:0 0 16px;padding:10px 12px;background:{{ $palette->tint }};color:{{ $palette->ink }};font-size:13px;font-weight:bold;">{{ __('cobro::correos.borrador') }}</p>
    @endif
    {{ $slot }}
</body>
@else
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table data-marco="empresa" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};color:#ffffff;font-size:18px;font-weight:bold;">
                            {{ $company->name }}
                        </td>
                    </tr>
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
                        <td style="padding:24px;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('cobro::correos.pie', ['empresa' => $company->name]) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
@endif
</html>
