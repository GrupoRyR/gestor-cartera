@use('App\Modules\Core\Contracts\Enums\CompanyCode')

{{--
    The SAP advisor fields of both Equipo forms, each with every advisor
    grouped by company: "asesores[]", the advisors linked to the user (one
    linked to someone else is disabled: the admin unlinks it on that person
    first), and "equipo[]", the team of a sales manager.
    $advisors: list<AdvisorRecord> by company code (TeamDirectory::
    advisorFields()); $owners: user names by id; $memberId: ?int (null on the
    form for a new user); $linked, $team: list<int> (checked boxes).
--}}
@php
    $label = fn ($advisor): string => __(
        $advisor->active ? 'core::equipo.asesor' : 'core::equipo.asesor_inactivo',
        ['codigo' => $advisor->code, 'nombre' => $advisor->name],
    );
    $ownerOf = fn ($advisor): ?string => $advisor->user_id === null || $advisor->user_id === $memberId
        ? null
        : ($owners[$advisor->user_id] ?? '');
    $hint = fn ($advisor, ?string $owner): ?string => implode(' · ', array_filter([
        $advisor->email,
        $owner === null ? null : __('core::equipo.formulario.vinculado_a', ['nombre' => $owner]),
    ])) ?: null;
@endphp

<fieldset aria-describedby="asesores-ayuda">
    <legend>{{ __('core::equipo.formulario.asesores') }}</legend>
    <p id="asesores-ayuda">{{ __('core::equipo.formulario.asesores_ayuda') }}</p>

    @forelse ($advisors as $company => $inCompany)
        <fieldset>
            <legend>{{ CompanyCode::from($company)->label() }} ({{ $company }})</legend>

            @foreach ($inCompany as $advisor)
                @php($owner = $ownerOf($advisor))
                <x-ui.checkbox
                    name="asesores[]"
                    :value="$advisor->id"
                    :label="$label($advisor)"
                    :hint="$hint($advisor, $owner)"
                    :checked="in_array($advisor->id, $linked, true)"
                    :disabled="$owner !== null"
                />
            @endforeach
        </fieldset>
    @empty
        <p>{{ __('core::equipo.formulario.sin_asesores') }}</p>
    @endforelse
</fieldset>

<fieldset aria-describedby="equipo-ayuda">
    <legend>{{ __('core::equipo.formulario.equipo') }}</legend>
    <p id="equipo-ayuda">{{ __('core::equipo.formulario.equipo_ayuda') }}</p>

    @forelse ($advisors as $company => $inCompany)
        <fieldset>
            <legend>{{ CompanyCode::from($company)->label() }} ({{ $company }})</legend>

            @foreach ($inCompany as $advisor)
                <x-ui.checkbox
                    name="equipo[]"
                    :value="$advisor->id"
                    :label="$label($advisor)"
                    :hint="$hint($advisor, null)"
                    :checked="in_array($advisor->id, $team, true)"
                />
            @endforeach
        </fieldset>
    @empty
        <p>{{ __('core::equipo.formulario.sin_asesores') }}</p>
    @endforelse
</fieldset>
