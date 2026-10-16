<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
    $this->member = TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);
    $this->other = TeamUsers::member('Pedro Ruiz', 'pedro.ruiz@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);

    $this->carlos = TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $this->member, 'carlos.perez@segurex.com');
    $this->beatriz = TeamUsers::advisor(CompanyCode::SGX, 7, 'Beatriz Mora', $this->other, 'beatriz.mora@segurex.com');
    $this->andres = TeamUsers::advisor(CompanyCode::SGX, 30, 'Andres Gil');
    $this->diana = TeamUsers::advisor(CompanyCode::SDC, 3, 'Diana Ruiz', active: false);
});

/**
 * The edit form of the member (Ana, asesor of SGX) with the advisors in
 * $advisors checked.
 *
 * @param  list<int>  $advisors
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function advisorMemberForm(array $advisors, array $changes = []): array
{
    return [
        'nombre' => 'Ana Gómez',
        'rol' => Role::SalesAdvisor->value,
        'empresas' => ['SGX'],
        'activo' => '1',
        'asesores' => $advisors,
        ...$changes,
    ];
}

/**
 * The edit form of a sales manager (Jessika, gerente comercial of SGX) with
 * the advisors in $team checked.
 *
 * @param  list<int>  $team
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function salesManagerForm(array $team, array $changes = []): array
{
    return [
        'nombre' => 'Jessika Ríos',
        'rol' => Role::SalesManager->value,
        'empresas' => ['SGX'],
        'activo' => '1',
        'equipo' => $team,
        ...$changes,
    ];
}

function salesManager(): User
{
    return TeamUsers::member('Jessika Ríos', 'jessika.rios@segurex.com', Role::SalesManager, [CompanyCode::SGX]);
}

it('lists every SAP advisor on the edit form, by company and then by name', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.edit', $this->member))->assertOk());
    $expected = [
        'Segurex Latam (SGX)' => ['30 · Andres Gil', '7 · Beatriz Mora', '12 · Carlos Pérez'],
        'Soluciones y Derivados (SDC)' => ['3 · Diana Ruiz (inactivo)'],
    ];

    expect(TeamUsers::advisorOptions($html, 'Asesor de SAP vinculado'))->toBe($expected)
        ->and(TeamUsers::advisorOptions($html, 'Equipo del gerente comercial'))->toBe($expected)
        ->and($html->byId("asesores-{$this->andres->id}")->getAttribute('name'))->toBe('asesores[]')
        ->and($html->byId("equipo-{$this->andres->id}")->getAttribute('name'))->toBe('equipo[]')
        ->and($html->descriptionsOf($html->one("//fieldset[normalize-space(legend)='Equipo del gerente comercial']")))
        ->toBe(['Solo aplica al rol Gerente comercial: verá la cartera de estos asesores.']);
});

it('checks the advisors of the user and disables the ones linked to someone else', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.edit', $this->member)));
    $own = $html->byId("asesores-{$this->carlos->id}");
    $taken = $html->byId("asesores-{$this->beatriz->id}");
    $free = $html->byId("asesores-{$this->andres->id}");

    expect($own->hasAttribute('checked'))->toBeTrue()
        ->and($own->hasAttribute('disabled'))->toBeFalse()
        ->and($html->descriptionsOf($own))->toBe(['carlos.perez@segurex.com'])
        ->and($taken->hasAttribute('checked'))->toBeFalse()
        ->and($taken->hasAttribute('disabled'))->toBeTrue()
        ->and($html->descriptionsOf($taken))->toBe(['beatriz.mora@segurex.com · Vinculado a Pedro Ruiz'])
        ->and($free->hasAttribute('checked'))->toBeFalse()
        ->and($free->hasAttribute('disabled'))->toBeFalse()
        ->and($html->descriptionsOf($free))->toBe([])
        ->and($html->byId("equipo-{$this->beatriz->id}")->hasAttribute('disabled'))->toBeFalse();
});

it('checks the team of a sales manager', function () {
    $manager = salesManager();
    AdvisorRecord::teamOf($manager)->attach([$this->carlos->id, $this->diana->id]);

    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.edit', $manager))->assertOk());

    expect($html->byId("equipo-{$this->carlos->id}")->hasAttribute('checked'))->toBeTrue()
        ->and($html->byId("equipo-{$this->diana->id}")->hasAttribute('checked'))->toBeTrue()
        ->and($html->byId("equipo-{$this->andres->id}")->hasAttribute('checked'))->toBeFalse()
        ->and($html->byId("asesores-{$this->carlos->id}")->hasAttribute('disabled'))->toBeTrue()
        ->and($html->descriptionsOf($html->byId("asesores-{$this->carlos->id}")))->toBe(['carlos.perez@segurex.com · Vinculado a Ana Gómez']);
});

it('links and unlinks advisors, with one audit entry each', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->member), advisorMemberForm([$this->andres->id, $this->diana->id]))
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHasNoErrors();

    [$linkedDiana, $linkedAndres] = TeamUsers::audits('core.advisor.linked');
    [$unlinked] = TeamUsers::audits('core.advisor.unlinked');

    expect(TeamUsers::advisorsOf($this->member))->toBe(['SDC-3', 'SGX-30'])
        ->and(TeamUsers::advisorsOf($this->other))->toBe(['SGX-7'])
        ->and($linkedAndres->subject_type)->toBe('advisor')
        ->and($linkedAndres->subject_id)->toBe((string) $this->andres->id)
        ->and($linkedAndres->user_id)->toBe($this->admin->id)
        ->and($linkedAndres->changes)->toEqual(['user_id' => ['old' => null, 'new' => $this->member->id]])
        ->and($linkedAndres->context)->toEqual(['company' => 'SGX', 'code' => 30, 'source' => 'equipo'])
        ->and($linkedDiana->subject_id)->toBe((string) $this->diana->id)
        ->and($linkedDiana->context)->toEqual(['company' => 'SDC', 'code' => 3, 'source' => 'equipo'])
        ->and($unlinked->subject_type)->toBe('advisor')
        ->and($unlinked->subject_id)->toBe((string) $this->carlos->id)
        ->and($unlinked->changes)->toEqual(['user_id' => ['old' => $this->member->id, 'new' => null]])
        ->and($unlinked->context)->toEqual(['company' => 'SGX', 'code' => 12, 'source' => 'equipo'])
        ->and(TeamUsers::audits('core.user.updated'))->toBe([]);
});

it('refuses an advisor linked to someone else and saves nothing', function () {
    $this->actingAs($this->admin)
        ->from(route('core.equipo.edit', $this->member))
        ->put(route('core.equipo.update', $this->member), advisorMemberForm(
            [$this->carlos->id, $this->andres->id, $this->beatriz->id],
            ['nombre' => 'Ana María Gómez'],
        ))
        ->assertRedirect(route('core.equipo.edit', $this->member))
        ->assertSessionHasErrors(['asesores' => 'Ese asesor ya está vinculado a otra persona.']);

    expect(TeamUsers::advisorsOf($this->member))->toBe(['SGX-12'])
        ->and(TeamUsers::advisorsOf($this->other))->toBe(['SGX-7'])
        ->and(User::query()->findOrFail($this->member->id)->name)->toBe('Ana Gómez')
        ->and(TeamUsers::audits('core.advisor.linked'))->toBe([])
        ->and(TeamUsers::audits('core.user.updated'))->toBe([]);
});

it('rejects advisors that do not exist', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->member), advisorMemberForm([999_999], ['equipo' => [999_999]]))
        ->assertSessionHasErrors([
            'asesores.0' => 'El valor elegido en asesor no es válido.',
            'equipo.0' => 'El valor elegido en asesor del equipo no es válido.',
        ]);

    expect(TeamUsers::advisorsOf($this->member))->toBe(['SGX-12']);
});

it('offers the advisors on the form for a new user', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.create'))->assertOk());
    $taken = $html->byId("asesores-{$this->carlos->id}");

    expect(array_keys(TeamUsers::advisorOptions($html, 'Asesor de SAP vinculado')))->toBe(['Segurex Latam (SGX)', 'Soluciones y Derivados (SDC)'])
        ->and(array_keys(TeamUsers::advisorOptions($html, 'Equipo del gerente comercial')))->toBe(['Segurex Latam (SGX)', 'Soluciones y Derivados (SDC)'])
        ->and($taken->hasAttribute('disabled'))->toBeTrue()
        ->and($html->descriptionsOf($taken))->toBe(['carlos.perez@segurex.com · Vinculado a Ana Gómez'])
        ->and($html->byId("asesores-{$this->andres->id}")->hasAttribute('disabled'))->toBeFalse()
        ->and($html->count('//input[@type="checkbox"][@checked]'))->toBe(0);
});

it('pre-registers a sales manager with their advisor and their team', function () {
    $this->actingAs($this->admin)->post(route('core.equipo.store'), [
        'nombre' => 'Jessika Ríos',
        'correo' => 'jessika.rios@segurex.com',
        'rol' => Role::SalesManager->value,
        'empresas' => ['SGX'],
        'asesores' => [$this->andres->id],
        'equipo' => [$this->carlos->id, $this->beatriz->id],
    ])->assertRedirect(route('core.equipo.index'))->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'jessika.rios@segurex.com')->sole();
    [$created] = TeamUsers::audits('core.user.created');
    [$linked] = TeamUsers::audits('core.advisor.linked');

    expect(TeamUsers::advisorsOf($user))->toBe(['SGX-30'])
        ->and(TeamUsers::teamOf($user))->toBe(['SGX-7', 'SGX-12'])
        ->and($created->changes)->toEqual([
            'name' => 'Jessika Ríos',
            'email' => 'jessika.rios@segurex.com',
            'role' => 'gerente_comercial',
            'companies' => ['SGX'],
            'team' => ['SGX-7', 'SGX-12'],
        ])
        ->and($linked->subject_id)->toBe((string) $this->andres->id)
        ->and($linked->changes)->toEqual(['user_id' => ['old' => null, 'new' => $user->id]])
        ->and($linked->context)->toEqual(['company' => 'SGX', 'code' => 30, 'source' => 'equipo']);
});

it('refuses to pre-register a person with an advisor linked to someone else', function () {
    $this->actingAs($this->admin)
        ->from(route('core.equipo.create'))
        ->post(route('core.equipo.store'), [
            'nombre' => 'Jessika Ríos',
            'correo' => 'jessika.rios@segurex.com',
            'asesores' => [$this->beatriz->id],
        ])
        ->assertRedirect(route('core.equipo.create'))
        ->assertSessionHasErrors(['asesores' => 'Ese asesor ya está vinculado a otra persona.']);

    expect(User::query()->where('email', 'jessika.rios@segurex.com')->exists())->toBeFalse()
        ->and(TeamUsers::advisorsOf($this->other))->toBe(['SGX-7'])
        ->and(TeamUsers::audits('core.user.created'))->toBe([]);
});

it('keeps no team for any other role', function () {
    $this->actingAs($this->admin)->post(route('core.equipo.store'), [
        'nombre' => 'Jessika Ríos',
        'correo' => 'jessika.rios@segurex.com',
        'rol' => Role::SalesAdvisor->value,
        'empresas' => ['SGX'],
        'equipo' => [$this->carlos->id],
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'jessika.rios@segurex.com')->sole();

    expect(TeamUsers::teamOf($user))->toBe([])
        ->and(TeamUsers::audits('core.user.created')[0]->changes['team'])->toBe([]);
});

it('saves the team of a sales manager and records the change', function () {
    $manager = salesManager();

    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $manager), salesManagerForm([$this->carlos->id, $this->beatriz->id]))
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHasNoErrors();

    [$updated] = TeamUsers::audits('core.user.updated');

    expect(TeamUsers::teamOf($manager))->toBe(['SGX-7', 'SGX-12'])
        ->and($updated->subject_id)->toBe((string) $manager->id)
        ->and($updated->context)->toEqual(['source' => 'equipo'])
        ->and($updated->changes)->toEqual(['team' => ['old' => [], 'new' => ['SGX-7', 'SGX-12']]]);
});

it('clears the team when the role changes to another', function () {
    $manager = salesManager();
    AdvisorRecord::teamOf($manager)->attach([$this->carlos->id, $this->diana->id]);

    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $manager), salesManagerForm(
            [$this->carlos->id, $this->diana->id],
            ['rol' => Role::PortfolioAnalyst->value],
        ))
        ->assertSessionHasNoErrors();

    expect(TeamUsers::teamOf($manager))->toBe([])
        ->and(TeamUsers::audits('core.user.updated'))->toHaveCount(1)
        ->and(TeamUsers::audits('core.user.updated')[0]->changes)->toEqual([
            'role' => ['old' => 'gerente_comercial', 'new' => 'analista_cartera'],
            'team' => ['old' => ['SDC-3', 'SGX-12'], 'new' => []],
        ]);
});

it('records nothing when the advisors and the team do not change', function () {
    $manager = salesManager();
    $this->andres->update(['user_id' => $manager->id]);
    AdvisorRecord::teamOf($manager)->attach([$this->carlos->id]);

    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $manager), salesManagerForm([$this->carlos->id], ['asesores' => [$this->andres->id]]))
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHasNoErrors();

    expect(TeamUsers::advisorsOf($manager))->toBe(['SGX-30'])
        ->and(TeamUsers::teamOf($manager))->toBe(['SGX-12'])
        ->and(TeamUsers::audits('core.advisor.linked'))->toBe([])
        ->and(TeamUsers::audits('core.advisor.unlinked'))->toBe([])
        ->and(TeamUsers::audits('core.user.updated'))->toBe([]);
});

it('shows the linked advisors in the list', function () {
    $this->diana->update(['user_id' => $this->member->id]);

    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index'))->assertOk());
    $advisorsOf = fn (string $name): string => $html->text("//table/tbody/tr[td[1][normalize-space(.)='{$name}']]/td[5]");

    expect($html->text('//table/thead/tr/th[5]'))->toBe('Asesor SAP')
        ->and($advisorsOf('Ana Gómez'))->toBe('SGX 12 · Carlos Pérez SDC 3 · Diana Ruiz')
        ->and($advisorsOf('Pedro Ruiz'))->toBe('SGX 7 · Beatriz Mora')
        ->and($advisorsOf('Laura Admin'))->toBe('');
});

it('drops the team rows of a removed advisor or of a user removed for good', function () {
    $manager = salesManager();
    $second = TeamUsers::member('Andrea Mejía', 'andrea.mejia@segurex.com', Role::SalesManager, [CompanyCode::SGX]);
    AdvisorRecord::teamOf($manager)->attach([$this->carlos->id, $this->andres->id]);
    AdvisorRecord::teamOf($second)->attach([$this->carlos->id]);

    $this->andres->delete();

    expect(DB::table('advisor_team')->where('advisor_id', $this->andres->id)->count())->toBe(0)
        ->and(TeamUsers::teamOf($manager))->toBe(['SGX-12']);

    $manager->forceDelete();

    expect(DB::table('advisor_team')->where('user_id', $manager->id)->count())->toBe(0)
        ->and(TeamUsers::teamOf($second))->toBe(['SGX-12']);
});

it('lets only admins link advisors', function () {
    $this->actingAs(CoreUsers::create(Role::SalesManager, CompanyCode::SGX))
        ->put(route('core.equipo.update', $this->member), advisorMemberForm([$this->andres->id]))
        ->assertForbidden();

    expect(TeamUsers::advisorsOf($this->member))->toBe(['SGX-12'])
        ->and(TeamUsers::audits('core.advisor.linked'))->toBe([]);
});
