<?php

namespace App\Modules\Core\Http\Requests\Team;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields both Equipo forms share: "nombre", "rol" (empty = no role),
 * "empresas[]", "asesores[]" (ids of the SAP advisors to link) and
 * "equipo[]" (ids of the advisors in a sales manager's team). Every role but
 * admin needs at least one company; an admin's companies are ignored
 * (TeamMemberDetails::companyCodes()), and so is the team of any role but
 * gerente_comercial (TeamMemberDetails::teamIds()). Whether an advisor is
 * free to link is checked by TeamMembers, under a lock.
 */
abstract class TeamMemberRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function memberRules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'rol' => ['nullable', 'string', Rule::in(array_keys(Role::options()))],
            'empresas' => [Rule::requiredIf(fn (): bool => $this->needsCompanies()), 'array'],
            'empresas.*' => ['string', Rule::in(array_keys(CompanyCode::options())), 'distinct'],
            'asesores' => ['array'],
            'asesores.*' => ['integer', 'distinct', Rule::exists('advisors', 'id')],
            'equipo' => ['array'],
            'equipo.*' => ['integer', 'distinct', Rule::exists('advisors', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => __('core::equipo.campos.nombre'),
            'correo' => __('core::equipo.campos.correo'),
            'rol' => __('core::equipo.campos.rol'),
            'empresas' => __('core::equipo.campos.empresas'),
            'empresas.*' => __('core::equipo.campos.empresa'),
            'activo' => __('core::equipo.campos.activo'),
            'asesores' => __('core::equipo.campos.asesores'),
            'asesores.*' => __('core::equipo.campos.asesor'),
            'equipo' => __('core::equipo.campos.equipo'),
            'equipo.*' => __('core::equipo.campos.asesor_equipo'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'correo.unique' => __('core::equipo.validacion.correo_existe'),
            'empresas.required' => __('core::equipo.validacion.empresas'),
        ];
    }

    public function memberName(): string
    {
        return $this->string('nombre')->trim()->value();
    }

    public function role(): ?Role
    {
        return Role::tryFrom($this->string('rol')->value());
    }

    /**
     * @return list<CompanyCode>
     */
    public function companies(): array
    {
        $chosen = $this->input('empresas', []);

        return array_values(array_map(
            fn (mixed $code): CompanyCode => CompanyCode::from((string) $code),
            is_array($chosen) ? $chosen : [],
        ));
    }

    /**
     * @return list<int>
     */
    public function advisorIds(): array
    {
        return $this->ids('asesores');
    }

    /**
     * @return list<int>
     */
    public function teamIds(): array
    {
        return $this->ids('equipo');
    }

    /**
     * @return list<int>
     */
    private function ids(string $field): array
    {
        $chosen = $this->input($field, []);

        return array_values(array_map(fn (mixed $id): int => (int) $id, is_array($chosen) ? $chosen : []));
    }

    private function needsCompanies(): bool
    {
        $role = $this->role();

        return $role !== null && $role !== Role::Admin;
    }
}
