@use('App\Modules\Cobro\Domain\Templates\TextDiff')
@use('App\Support\Formatting\DateFormat')

{{--
    Notice of SendTemplateChangeNotice when a weekly collection template
    changes (audit S-08). Plain HTML with inline styles only, so any mail
    client shows it. Every text the user typed goes through {{ }}: links,
    account numbers and tags show verbatim, never as HTML.
    $company: CompanyCode; $change: "updated" or "reset"; $editor: ?User;
    $changedAt: ISO 8601; $changes: field => {old, new}.
--}}
@php
    $fields = ['subject' => 'asunto', 'intro' => 'inicio', 'closing' => 'cierre'];
    $styles = [
        TextDiff::SAME => '',
        TextDiff::REMOVED => 'background:#ffebe9;color:#82071e;',
        TextDiff::ADDED => 'background:#e6ffec;color:#055d20;',
    ];
    $marks = [TextDiff::SAME => ' ', TextDiff::REMOVED => '−', TextDiff::ADDED => '+'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('cobro::plantillas.aviso.titulo') }}</title>
</head>
<body>
    <h1>{{ __('cobro::plantillas.aviso.titulo') }}</h1>

    <p>{{ __('cobro::plantillas.aviso.intro') }}</p>

    <ul>
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

    <p><a href="{{ route('cobro.plantillas.edit', ['empresa' => $company->value]) }}">{{ __('cobro::plantillas.aviso.abrir') }}</a></p>

    <p>{{ __('cobro::plantillas.aviso.leyenda') }}</p>

    @foreach ($fields as $field => $label)
        @continue (! isset($changes[$field]))

        <h2>{{ __("cobro::plantillas.campos.{$label}") }}</h2>
        <table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #d9d9d9;font-family:Consolas,monospace;font-size:13px;">
            @foreach (TextDiff::lines($changes[$field]['old'], $changes[$field]['new']) as $entry)
                <tr>
                    <td style="padding:2px 8px;white-space:pre-wrap;{{ $styles[$entry['type']] }}">{{ $marks[$entry['type']] }} {{ $entry['line'] }}</td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <p>{{ __('cobro::plantillas.aviso.pie') }}</p>
</body>
</html>
