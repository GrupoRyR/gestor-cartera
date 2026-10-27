@use('App\Support\Formatting\DateFormat')

{{--
    E-mail of core:verificar-auditoria when the hash chain of the audit
    trail is broken. Plain HTML without styles, so any mail client shows
    it. Only ids, counts and the reason: never the contents of an entry.
    $at: when the check ran (Bogotá); $id: ?int, the first entry with a
    problem; $reason: the key of core::auditoria.motivos; $checked: how
    many entries passed before it.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('core::auditoria.correo.titulo') }}</title>
</head>
<body>
    <h1>{{ __('core::auditoria.correo.titulo') }}</h1>

    <p>{{ __('core::auditoria.correo.intro', ['fecha' => DateFormat::date($at), 'hora' => $at->format('H:i')]) }}</p>

    <h2>{{ __('core::auditoria.correo.entrada') }}</h2>
    <p>
        @if ($id === null)
            {{ __('core::auditoria.correo.sin_entrada', ['motivo' => __("core::auditoria.motivos.{$reason}")]) }}
        @else
            {{ __('core::auditoria.correo.entrada_detalle', ['id' => $id, 'motivo' => __("core::auditoria.motivos.{$reason}")]) }}
        @endif
    </p>
    <p>{{ trans_choice('core::auditoria.correo.revisadas', $checked, ['total' => $checked]) }}</p>

    <h2>{{ __('core::auditoria.correo.que_hacer') }}</h2>
    <p>{{ __('core::auditoria.correo.pasos') }}</p>

    <p>{{ __('core::auditoria.correo.pie') }}</p>
</body>
</html>
