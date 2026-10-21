<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Tests\Modules\Core\Migracion\MigrationFolder;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * core:migrar-usuarios with the invented exports in fixtures/: five Fase 1
 * users (two of them broken) and three Supabase users. Andrés is in both
 * sources: gestor in Fase 1, gerente of advisors 84 and 99 in Supabase.
 * Camilo, a Supabase advisor, has SLP codes 84 (free), 85 (linked to
 * someone else) and 99 (unknown in SGX). Diana, a Supabase admin, has code
 * 77, which is ignored.
 */
beforeEach(function () {
    $this->folder = MigrationFolder::with('fase1_users.csv', 'supabase_usuarios.csv');
    $this->owner = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    $this->free = AdvisorRecord::factory()->create(['code' => 84]);
    $this->taken = AdvisorRecord::factory()->create(['code' => 85, 'user_id' => $this->owner->id]);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SDC, 'code' => 99]);
});

afterEach(function () {
    MigrationFolder::remove($this->folder);
});

/**
 * @return array{name: string, active: bool, roles: list<string>, companies: list<string>, team: list<int>}
 */
function migracionUser(string $email): array
{
    $user = User::query()->where('email', $email)->firstOrFail();
    $roles = $user->getRoleNames()->sort()->values()->all();
    $companies = CompanyRecord::assignedTo($user)->pluck('companies.code')
        ->map(fn (CompanyCode $code): string => $code->value)
        ->sort()
        ->values()
        ->all();

    $team = AdvisorRecord::teamOf($user)->pluck('code')->sort()->values()->all();

    return ['name' => $user->name, 'active' => (bool) $user->is_active, 'roles' => $roles, 'companies' => $companies, 'team' => $team];
}

it('imports the users of both sources, maps roles, companies and teams and merges the same e-mail', function () {
    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_users.csv', 5, 3, 0, 2],
            ['Supabase', 'supabase_usuarios.csv', 3, 3, 0, 0],
        ])
        ->expectsTable(MigrationFolder::REJECTIONS, [
            ['fase1_users.csv', 5, '4', 'campo vacío: email'],
            ['fase1_users.csv', 6, '5', 'valor no reconocido en rol: jefe (se espera admin, gestor)'],
        ])
        ->expectsTable(MigrationFolder::WARNINGS, [
            ['supabase_usuarios.csv', 2, '7f1c', 'ya tiene el rol analista_cartera; Supabase dice gerente_comercial, se conserva el actual'],
            ['supabase_usuarios.csv', 2, '7f1c', 'no se vinculan los códigos 84, 99: el usuario conserva el rol analista_cartera'],
            ['supabase_usuarios.csv', 3, '8a2d', 'el código de asesor 85 ya está vinculado a otro usuario'],
            ['supabase_usuarios.csv', 3, '8a2d', 'el código de asesor 99 no existe en la app (SGX)'],
        ])
        ->assertSuccessful();

    expect(migracionUser('laura.restrepo@segurex.com'))->toBe(['name' => 'Laura Restrepo', 'active' => true, 'roles' => ['admin'], 'companies' => ['SDC', 'SGX'], 'team' => []])
        ->and(migracionUser('andres.ospina@segurex.com'))->toBe(['name' => 'Andrés Ospina', 'active' => true, 'roles' => ['analista_cartera'], 'companies' => ['SDC', 'SGX'], 'team' => []])
        ->and(migracionUser('paola.zuluaga@segurex.com'))->toBe(['name' => 'Paola Zuluaga', 'active' => false, 'roles' => ['analista_cartera'], 'companies' => ['SDC', 'SGX'], 'team' => []])
        ->and(migracionUser('camilo.duque@segurex.com'))->toBe(['name' => 'Camilo Duque', 'active' => true, 'roles' => ['asesor'], 'companies' => ['SGX'], 'team' => []])
        ->and(migracionUser('diana.velez@segurex.com'))->toBe(['name' => 'Diana Vélez', 'active' => false, 'roles' => ['admin'], 'companies' => ['SGX'], 'team' => []])
        ->and(User::query()->where('email', 'rol.raro@segurex.com')->exists())->toBeFalse();

    $camilo = User::query()->where('email', 'camilo.duque@segurex.com')->firstOrFail();

    expect($this->free->refresh()->user_id)->toBe($camilo->id)
        ->and($this->taken->refresh()->user_id)->toBe($this->owner->id)
        ->and($camilo->microsoft_id)->toBeNull()
        ->and($camilo->password)->toBeNull();
});

it('leaves everything as it was when it runs again', function () {
    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])->assertSuccessful();
    $users = User::query()->count();

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_users.csv', 5, 0, 3, 2],
            ['Supabase', 'supabase_usuarios.csv', 3, 0, 3, 0],
        ])
        ->expectsTable(MigrationFolder::SKIPPED, [
            ['fase1_users.csv', 'ya existe', 3],
            ['supabase_usuarios.csv', 'ya existe', 3],
        ])
        ->assertSuccessful();

    expect(User::query()->count())->toBe($users)
        ->and(migracionUser('andres.ospina@segurex.com')['roles'])->toBe(['analista_cartera'])
        ->and(migracionUser('andres.ospina@segurex.com')['team'])->toBe([])
        ->and(AdvisorRecord::query()->where('user_id', '!=', $this->owner->id)->count())->toBe(1);
});

it('saves nothing in a dry run but reports the same counts', function () {
    $users = User::query()->count();

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder, '--dry-run' => true])
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_users.csv', 5, 3, 0, 2],
            ['Supabase', 'supabase_usuarios.csv', 3, 3, 0, 0],
        ])
        ->expectsOutputToContain('Modo de prueba: no se guardó nada.')
        ->assertSuccessful();

    expect(User::query()->count())->toBe($users)
        ->and($this->free->refresh()->user_id)->toBeNull()
        ->and(AuditLogRecord::query()->count())->toBe(0);
});

it('only adds what an existing user lacks and keeps their name, activation, role and Microsoft id', function () {
    $andres = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SDC);
    $andres->forceFill(['email' => 'andres.ospina@segurex.com', 'name' => 'Andrés Felipe Ospina', 'is_active' => false])->save();
    $microsoftId = $andres->microsoft_id;

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_users.csv', 5, 2, 1, 2],
            ['Supabase', 'supabase_usuarios.csv', 3, 3, 0, 0],
        ])
        ->assertSuccessful();

    expect(migracionUser('andres.ospina@segurex.com'))->toBe(['name' => 'Andrés Felipe Ospina', 'active' => false, 'roles' => ['analista_cartera'], 'companies' => ['SDC', 'SGX'], 'team' => []])
        ->and($andres->refresh()->microsoft_id)->toBe($microsoftId);
});

it('rejects a user who is deleted in the app and does not restore them', function () {
    $laura = CoreUsers::create(Role::Admin);
    $laura->forceFill(['email' => 'laura.restrepo@segurex.com'])->save();
    $laura->delete();

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsTable(MigrationFolder::REJECTIONS, [
            ['fase1_users.csv', 2, '1', 'el usuario está eliminado en la app'],
            ['fase1_users.csv', 5, '4', 'campo vacío: email'],
            ['fase1_users.csv', 6, '5', 'valor no reconocido en rol: jefe (se espera admin, gestor)'],
        ])
        ->assertSuccessful();

    expect($laura->refresh()->trashed())->toBeTrue()
        ->and(CompanyRecord::assignedTo($laura)->count())->toBe(0);
});

it('rejects a row that is not UTF-8 and imports the rest', function () {
    MigrationFolder::put($this->folder, 'fase1_users.csv', "id,nombre,email,rol,empresa,activo\n1,Jos\xE9 Duque,jose@segurex.com,gestor,SGX,1\n2,Ana Vélez,ana@segurex.com,gestor,SGX,1\n");

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsTable(MigrationFolder::REJECTIONS, [
            ['fase1_users.csv', 2, '1', 'la fila no está en UTF-8: revisa que el archivo se exportó en utf-8'],
        ])
        ->assertSuccessful();

    expect(User::query()->where('email', 'jose@segurex.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'ana@segurex.com')->exists())->toBeTrue();
});

it('records each created or changed user and each linked advisor in the audit trail', function () {
    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])->assertSuccessful();

    $andres = User::query()->where('email', 'andres.ospina@segurex.com')->firstOrFail();
    $updated = AuditLogRecord::query()->where('action', 'core.user.updated')->where('subject_id', (string) $andres->id)->sole();

    expect(AuditLogRecord::query()->where('action', 'core.user.created')->count())->toBe(5)
        ->and(AuditLogRecord::query()->where('action', 'core.advisor.linked')->where('subject_id', (string) $this->free->id)->exists())->toBeTrue()
        ->and($updated->changes)->toEqual([
            'companies' => ['old' => ['SDC'], 'new' => ['SDC', 'SGX']],
        ])
        ->and($updated->context)->toEqual(['source' => 'migracion', 'file' => 'supabase_usuarios.csv', 'line' => 2]);
});

it('adds the advisors of a sales manager to their team without dropping the ones already in it', function () {
    $andres = CoreUsers::create(Role::SalesManager, CompanyCode::SGX);
    $andres->forceFill(['email' => 'andres.ospina@segurex.com'])->save();
    AdvisorRecord::teamOf($andres)->attach($this->taken->id);

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsTable(MigrationFolder::WARNINGS, [
            ['fase1_users.csv', 3, '2', 'ya tiene el rol gerente_comercial; Fase 1 dice analista_cartera, se conserva el actual'],
            ['supabase_usuarios.csv', 2, '7f1c', 'el código de asesor 99 no existe en la app (SGX)'],
            ['supabase_usuarios.csv', 3, '8a2d', 'el código de asesor 85 ya está vinculado a otro usuario'],
            ['supabase_usuarios.csv', 3, '8a2d', 'el código de asesor 99 no existe en la app (SGX)'],
        ])
        ->assertSuccessful();

    $changes = AuditLogRecord::query()
        ->where('action', 'core.user.updated')
        ->where('subject_id', (string) $andres->id)
        ->orderBy('id')
        ->pluck('changes')
        ->all();

    expect(migracionUser('andres.ospina@segurex.com'))->toMatchArray(['roles' => ['gerente_comercial'], 'companies' => ['SDC', 'SGX'], 'team' => [84, 85]])
        ->and($changes)->toEqual([
            ['companies' => ['old' => ['SGX'], 'new' => ['SDC', 'SGX']]],
            ['team' => ['old' => ['SGX-85'], 'new' => ['SGX-84', 'SGX-85']]],
        ]);
});

it('imports the source it finds and says which file is missing', function () {
    unlink($this->folder.DIRECTORY_SEPARATOR.'supabase_usuarios.csv');

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsOutputToContain('No encontré supabase_usuarios.csv')
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_users.csv', 5, 3, 0, 2],
        ])
        ->assertSuccessful();
});

it('fails when the folder has none of its files', function () {
    $empty = MigrationFolder::with();

    try {
        $this->artisan('core:migrar-usuarios', ['--dir' => $empty])
            ->expectsOutputToContain('No encontré ninguno de estos archivos')
            ->assertFailed();
    } finally {
        MigrationFolder::remove($empty);
    }
});

it('fails without a folder or with one that does not exist', function () {
    $this->artisan('core:migrar-usuarios')
        ->expectsOutputToContain('Indica la carpeta de los archivos con --dir')
        ->assertFailed();

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder.DIRECTORY_SEPARATOR.'no-existe'])
        ->expectsOutputToContain('no existe')
        ->assertFailed();
});

it('fails naming the missing columns and imports nothing', function () {
    MigrationFolder::put($this->folder, 'supabase_usuarios.csv', "id,email,nombre\n1,ana@segurex.com,Ana\n");
    $users = User::query()->count();

    $this->artisan('core:migrar-usuarios', ['--dir' => $this->folder])
        ->expectsOutputToContain('Al archivo supabase_usuarios.csv le faltan columnas: rol, slp_codes, activo.')
        ->assertFailed();

    expect(User::query()->count())->toBe($users);
});
