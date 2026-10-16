@use('App\Modules\Core\Contracts\Enums\CompanyCode')
@use('App\Modules\Core\Contracts\Enums\Role')

{{--
    The role and company fields of both Equipo forms.
    $role: ?Role; $companies: list<CompanyCode> (checked boxes).
--}}
<x-ui.select
    name="rol"
    :label="__('core::equipo.formulario.rol')"
    :options="Role::options()"
    :value="$role"
    :placeholder="__('core::equipo.sin_rol')"
    :hint="__('core::equipo.formulario.rol_ayuda')"
/>

<fieldset aria-describedby="empresas-ayuda">
    <legend>{{ __('core::equipo.formulario.empresas') }}</legend>
    <p id="empresas-ayuda">{{ __('core::equipo.formulario.empresas_ayuda') }}</p>

    @foreach (CompanyCode::cases() as $company)
        <x-ui.checkbox
            name="empresas[]"
            :value="$company"
            :label="$company->label().' ('.$company->value.')'"
            :checked="in_array($company, $companies, true)"
        />
    @endforeach
</fieldset>
