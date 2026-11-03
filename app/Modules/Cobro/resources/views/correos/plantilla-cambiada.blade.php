@use('App\Modules\Cobro\Domain\Templates\TextDiff')
@use('App\Modules\Core\Contracts\Data\BrandPalette')
@use('App\Support\Formatting\DateFormat')

{{--
    Notice of SendTemplateChangeNotice when a weekly collection template
    changes (audit S-08). Every text the user typed goes through {{ }}:
    links, account numbers and tags show verbatim, never as HTML.
    $company: CompanyCode; $change: "updated" or "reset"; $editor: ?User;
    $changedAt: ISO 8601; $changes: field => {old, new}.

    Brand v2, inline styles only (mail clients ignore stylesheets and CSS
    variables), in the palette of the company whose template changed: a
    600 px column with the head band behind the white title (7.01:1
    Segurex, 11.50:1 SDC) and an accent border, section titles in ink,
    long text in text, the button behind the white link (4.51:1 Segurex,
    6.57:1 SDC), and the brand's status colors for the diff (#b42318 on
    #fde7e4: 5.55:1; #1d7a46 on #e5f3eb: 4.68:1). No image: the logo is
    separate work.
--}}
@php
    $palette = BrandPalette::official($company);
    $fields = ['subject' => 'asunto', 'intro' => 'inicio', 'closing' => 'cierre'];
    $styles = [
        TextDiff::SAME => '',
        TextDiff::REMOVED => 'background:#fde7e4;color:#b42318;',
        TextDiff::ADDED => 'background:#e5f3eb;color:#1d7a46;',
    ];
    $marks = [TextDiff::SAME => ' ', TextDiff::REMOVED => '−', TextDiff::ADDED => '+'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('cobro::plantillas.aviso.titulo') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f4f3;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:15px;line-height:1.5;color:{{ $palette->text }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f3;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e0dd;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $palette->head }};border-bottom:4px solid {{ $palette->accent }};">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">{{ __('cobro::plantillas.aviso.titulo') }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;">{{ __('cobro::plantillas.aviso.intro') }}</p>

                            <ul style="margin:0 0 14px;padding:0 0 0 20px;">
                                <li>{{ __('cobro::plantillas.aviso.empresa', ['empresa' => $company->label()]) }}</li>
                                <li>
                                    @if ($editor === null)
                                        {{ __('cobro::plantillas.aviso.quien_desconocido') }}
                                    @else
                                        {{ __('cobro::plantillas.aviso.quien', ['nombre' => $editor->name, 'correo' => $editor->email]) }}
                                    @endif
                                </li>
                                <li>{{ __('cobro::plantillas.aviso.cuando', ['fecha' => DateFormat::dateTime($changedAt)]) }}</li>
                                <li>{{ __('cobro::plantillas.aviso.cambio', ['cambio' => __("cobro::plantillas.aviso.cambios.{$change}")]) }}</li>
                            </ul>

                            <p style="margin:0 0 20px;">
                                <a href="{{ route('cobro.plantillas.edit', ['empresa' => $company->value]) }}" style="display:inline-block;padding:10px 18px;background:{{ $palette->button }};color:#ffffff;font-weight:bold;text-decoration:none;">{{ __('cobro::plantillas.aviso.abrir') }}</a>
                            </p>

                            <p style="margin:0 0 14px;">{{ __('cobro::plantillas.aviso.leyenda') }}</p>

                            @foreach ($fields as $field => $label)
                                @continue (! isset($changes[$field]))

                                <h2 style="margin:20px 0 8px;font-size:16px;line-height:1.3;color:{{ $palette->ink }};">{{ __("cobro::plantillas.campos.{$label}") }}</h2>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #e3e0dd;font-size:13px;">
                                    @foreach (TextDiff::lines($changes[$field]['old'], $changes[$field]['new']) as $entry)
                                        <tr>
                                            <td style="padding:2px 8px;white-space:pre-wrap;word-break:break-word;{{ $styles[$entry['type']] }}">{{ $marks[$entry['type']] }} {{ $entry['line'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #e3e0dd;color:#6a6b6e;font-size:12px;">
                            {{ __('cobro::plantillas.aviso.pie') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
