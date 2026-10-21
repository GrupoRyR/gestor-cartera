<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;

/**
 * core:migrar-usuarios: imports the users of Cartera Fase 1
 * (fase1_users.csv) and then those of Supabase (supabase_usuarios.csv).
 *
 * Fase 1: admin becomes Admin with both companies; gestor becomes
 * PortfolioAnalyst of its company (ALL: both). Supabase: asesor becomes
 * SalesAdvisor, gerente SalesManager and admin Admin, all of SGX. An
 * advisor's slp_codes link the SGX advisors with those codes; a manager's
 * add those advisors to their team. The same e-mail in both sources is one
 * user with the companies of both and the role of the first source (see
 * LegacyUsers).
 */
final class MigrateUsersCommand extends MigrationCommand
{
    private const string FASE1 = 'fase1_users.csv';

    private const string SUPABASE = 'supabase_usuarios.csv';

    /**
     * @var string
     */
    protected $name = 'core:migrar-usuarios';

    /**
     * @var string
     */
    protected $description = 'Importa los usuarios de Cartera Fase 1 y de Supabase desde los CSV exportados';

    protected function files(): array
    {
        return [
            self::FASE1 => ['id', 'nombre', 'email', 'rol', 'empresa', 'activo'],
            self::SUPABASE => ['id', 'email', 'nombre', 'rol', 'slp_codes', 'activo'],
        ];
    }

    protected function import(array $files, ImportReport $report): void
    {
        $users = $this->laravel->make(LegacyUsers::class);

        if (isset($files[self::FASE1])) {
            $this->eachRow($files[self::FASE1], $report, fn (CsvRow $row): bool => $users->save($this->fromFase1($row), $row, $report));
        }

        if (isset($files[self::SUPABASE])) {
            $this->eachRow($files[self::SUPABASE], $report, fn (CsvRow $row): bool => $users->save($this->fromSupabase($row), $row, $report));
        }
    }

    private function fromFase1(CsvRow $row): LegacyUser
    {
        $email = $row->email('email');
        $name = $row->string('nombre');
        $role = $row->oneOf('rol', ['admin', 'gestor']) === 'admin' ? Role::Admin : Role::PortfolioAnalyst;
        $company = $row->oneOf('empresa', ['SGX', 'SDC', 'ALL']);

        return new LegacyUser(
            email: $email,
            name: $name,
            active: $row->bool('activo'),
            role: $role,
            companies: $role === Role::Admin || $company === 'ALL' ? CompanyCode::cases() : [CompanyCode::from($company)],
        );
    }

    private function fromSupabase(CsvRow $row): LegacyUser
    {
        $email = $row->email('email');
        $name = $row->string('nombre');
        $role = match ($row->oneOf('rol', ['asesor', 'gerente', 'admin'])) {
            'asesor' => Role::SalesAdvisor,
            'gerente' => Role::SalesManager,
            default => Role::Admin,
        };

        // An advisor's codes are their own; a manager's, the advisors they
        // supervise (legacy portal-asesores, 06_rol_gerente.sql). An admin's
        // codes mean nothing here.
        $codes = $role === Role::Admin ? [] : $row->intList('slp_codes');

        return new LegacyUser(
            email: $email,
            name: $name,
            active: $row->bool('activo'),
            role: $role,
            companies: [CompanyCode::SGX],
            advisorCodes: $role === Role::SalesAdvisor ? $codes : [],
            teamCodes: $role === Role::SalesManager ? $codes : [],
        );
    }
}
