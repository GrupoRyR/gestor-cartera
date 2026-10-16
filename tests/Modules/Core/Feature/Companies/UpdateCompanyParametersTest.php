<?php

use App\Modules\Core\Application\Companies\RecomputeCompanyStatuses;
use App\Modules\Core\Application\Companies\UpdateCompanyParameters;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Domain\Status\StatusParameters;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Core\Support\RobotBatches;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
});

/**
 * The saved grace days and due-soon window of a company.
 *
 * @return array{int, int}
 */
function savedCompanyParameters(string $code): array
{
    $row = CompanyRecord::query()->findOrFail($code);

    return [$row->grace_days, $row->due_soon_days];
}

it('saves the parameters and audits only what changed', function () {
    $this->actingAs($this->admin)
        ->put(route('core.empresas.update', 'SGX'), ['grace_days' => '20', 'due_soon_days' => '5'])
        ->assertRedirect(route('core.empresas.index'))
        ->assertSessionHas('status', 'Guardamos los parámetros de Segurex Latam. Los estados de sus clientes se recalculan en unos minutos.');

    [$entry] = TeamUsers::audits('core.company.updated');

    expect(savedCompanyParameters('SGX'))->toBe([20, 5])
        ->and(savedCompanyParameters('SDC'))->toBe([5, 5])
        ->and(TeamUsers::audits('core.company.updated'))->toHaveCount(1)
        ->and($entry->subject_type)->toBe('company')
        ->and($entry->subject_id)->toBe('SGX')
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->changes)->toEqual(['grace_days' => ['old' => 15, 'new' => 20]])
        ->and($entry->context)->toBeNull();
});

it('audits both parameters when both change', function () {
    $this->actingAs($this->admin)->put(route('core.empresas.update', 'SDC'), ['grace_days' => '8', 'due_soon_days' => '3']);

    expect(savedCompanyParameters('SDC'))->toBe([8, 3])
        ->and(TeamUsers::audits('core.company.updated')[0]->changes)->toEqual([
            'grace_days' => ['old' => 5, 'new' => 8],
            'due_soon_days' => ['old' => 5, 'new' => 3],
        ]);
});

it('records nothing and queues nothing when nothing changes', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->put(route('core.empresas.update', 'SGX'), ['grace_days' => '15', 'due_soon_days' => '5'])
        ->assertRedirect(route('core.empresas.index'))
        ->assertSessionHas('status', 'Los parámetros de Segurex Latam no cambiaron.');

    expect(savedCompanyParameters('SGX'))->toBe([15, 5])
        ->and(TeamUsers::audits('core.company.updated'))->toBe([]);

    Queue::assertNothingPushed();
});

it('queues one recompute of the statuses of that company', function () {
    Queue::fake();

    $this->actingAs($this->admin)->put(route('core.empresas.update', 'SDC'), ['grace_days' => '8', 'due_soon_days' => '5']);

    Queue::assertCount(1);
    Queue::assertPushed(RecomputeCompanyStatuses::class, fn (RecomputeCompanyStatuses $job): bool => $job->company === CompanyCode::SDC
        && $job->timeout === 300
        && $job->maxExceptions === 1);

    // The database queue stores the job serialized.
    expect(unserialize(serialize(new RecomputeCompanyStatuses(CompanyCode::SDC)))->company)->toBe(CompanyCode::SDC);
});

it('refreshes the company catalog for the rest of the request', function () {
    Queue::fake();
    $current = app(CurrentCompany::class);

    expect($current->of(CompanyCode::SGX)->graceDays)->toBe(15);

    $changed = app(UpdateCompanyParameters::class)->update(CompanyCode::SGX, new StatusParameters(graceDays: 20, dueSoonDays: 7));

    expect($changed)->toBeTrue()
        ->and($current->of(CompanyCode::SGX)->graceDays)->toBe(20)
        ->and(app(CompanyCatalog::class)->parameters(CompanyCode::SGX))->toEqual(new StatusParameters(graceDays: 20, dueSoonDays: 7));
});

it('creates the row of a company that was missing it', function () {
    Queue::fake();
    CompanyRecord::query()->whereKey('SDC')->delete();
    app(CompanyCatalog::class)->refresh();

    $changed = app(UpdateCompanyParameters::class)->update(CompanyCode::SDC, new StatusParameters(graceDays: 9, dueSoonDays: 5));
    $row = CompanyRecord::query()->findOrFail('SDC');

    expect($changed)->toBeTrue()
        ->and($row->name)->toBe('Soluciones y Derivados')
        ->and([$row->grace_days, $row->due_soon_days])->toBe([9, 5])
        ->and(TeamUsers::audits('core.company.updated')[0]->changes)->toEqual(['grace_days' => ['old' => 5, 'new' => 9]]);
});

it('recomputes the client statuses with the new grace days', function () {
    // 16 days past due: overdue with the 15 grace days of SGX.
    $load = RobotBatches::install($this->app)->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['fecha_vencimiento' => '2026-09-29']),
    ]);
    $client = ClientRecord::query()->sole();

    expect($client->status)->toBe(ClientStatus::Overdue);

    $this->actingAs($this->admin)
        ->put(route('core.empresas.update', 'SGX'), ['grace_days' => '20', 'due_soon_days' => '5'])
        ->assertRedirect(route('core.empresas.index'));

    [$statusChange] = TeamUsers::audits('core.client.status_changed');
    $activity = ActivityRecord::query()->sole();

    expect($client->refresh()->status)->toBe(ClientStatus::DueSoon)
        ->and(TeamUsers::audits('core.client.status_changed'))->toHaveCount(1)
        ->and($statusChange->subject_id)->toBe((string) $client->id)
        ->and($statusChange->changes)->toEqual(['status' => ['old' => 'vencida', 'new' => 'por_vencer']])
        ->and($statusChange->context)->toEqual(['load_id' => $load->id, 'cutoff_date' => '2026-10-15', 'source' => 'parameters'])
        ->and($activity->type)->toBe(ActivityType::StatusChange)
        ->and($activity->summary)->toBe('Estado automático: de «Vencida» a «Por vencer»')
        ->and($activity->user_id)->toBeNull()
        ->and($activity->metadata)->toEqual(['from' => 'vencida', 'to' => 'por_vencer', 'load_id' => $load->id, 'source' => 'parameters']);
});

it('leaves the clients of the other company alone', function () {
    $robot = RobotBatches::install($this->app);
    $robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable(['fecha_vencimiento' => '2026-09-29'])]);
    $robot->receivables(CompanyCode::SDC, '2026-10-15', [RobotBatches::receivable(['fecha_vencimiento' => '2026-10-08'])]);

    $this->actingAs($this->admin)->put(route('core.empresas.update', 'SDC'), ['grace_days' => '10', 'due_soon_days' => '5']);

    expect(ClientRecord::query()->where('company', CompanyCode::SGX)->sole()->status)->toBe(ClientStatus::Overdue)
        ->and(ClientRecord::query()->where('company', CompanyCode::SDC)->sole()->status)->toBe(ClientStatus::DueSoon)
        ->and(TeamUsers::audits('core.client.status_changed'))->toHaveCount(1);
});

it('explains validation errors in Spanish and saves nothing', function (array $input, array $errors) {
    Queue::fake();

    $this->actingAs($this->admin)
        ->from(route('core.empresas.edit', 'SGX'))
        ->put(route('core.empresas.update', 'SGX'), $input)
        ->assertRedirect(route('core.empresas.edit', 'SGX'))
        ->assertSessionHasErrors($errors);

    expect(savedCompanyParameters('SGX'))->toBe([15, 5])
        ->and(TeamUsers::audits('core.company.updated'))->toBe([]);

    Queue::assertNothingPushed();
})->with([
    'nothing sent' => [[], [
        'grace_days' => 'El campo días de gracia es obligatorio.',
        'due_soon_days' => 'El campo ventana de «Por vencer» es obligatorio.',
    ]],
    'negative' => [['grace_days' => '-1', 'due_soon_days' => '-3'], [
        'grace_days' => 'El campo días de gracia debe ser al menos 0.',
        'due_soon_days' => 'El campo ventana de «Por vencer» debe ser al menos 0.',
    ]],
    'too many days' => [['grace_days' => '121', 'due_soon_days' => '61'], [
        'grace_days' => 'El campo días de gracia no debe ser mayor que 120.',
        'due_soon_days' => 'El campo ventana de «Por vencer» no debe ser mayor que 60.',
    ]],
    'not whole numbers' => [['grace_days' => '15.5', 'due_soon_days' => 'cinco'], [
        'grace_days' => 'El campo días de gracia debe ser un número entero.',
        'due_soon_days' => 'El campo ventana de «Por vencer» debe ser un número entero.',
    ]],
]);
