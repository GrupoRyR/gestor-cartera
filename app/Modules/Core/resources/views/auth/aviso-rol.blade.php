@use('App\Support\Formatting\DateFormat')

{{--
    E-mail of SendAdvisorRoleNotice when a sign-in gives the "asesor" role
    on its own (audit S-17). Plain HTML without styles, so any mail client
    shows it. $user: the User; $at: when they signed in (Bogotá);
    $companies: list<string>, the company names the role covers; $advisors:
    list<string>, the linked SAP advisors as "company code".
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ __('core::ingreso.aviso_rol.titulo') }}</title>
</head>
<body>
    <h1>{{ __('core::ingreso.aviso_rol.titulo') }}</h1>

    <p>{{ __('core::ingreso.aviso_rol.intro') }}</p>

    <p>
        {{ __('core::ingreso.aviso_rol.usuario', ['nombre' => $user->name, 'correo' => $user->email]) }}<br>
        {{ __('core::ingreso.aviso_rol.cuando', ['fecha' => DateFormat::date($at), 'hora' => $at->format('H:i')]) }}<br>
        {{ __('core::ingreso.aviso_rol.empresas', ['empresas' => implode(', ', $companies)]) }}<br>
        {{ __('core::ingreso.aviso_rol.asesores', ['asesores' => implode(', ', $advisors)]) }}
    </p>

    <h2>{{ __('core::ingreso.aviso_rol.que_hacer') }}</h2>
    <p>{{ __('core::ingreso.aviso_rol.pasos') }}</p>
    <p><a href="{{ route('core.equipo.edit', ['user' => $user->id]) }}">{{ __('core::ingreso.aviso_rol.enlace') }}</a></p>

    <p>{{ __('core::ingreso.aviso_rol.pie') }}</p>
</body>
</html>
