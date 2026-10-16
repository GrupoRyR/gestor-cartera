<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Infrastructure\Ingest\ImportAdvisors;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Core\Support\RobotBatches;

beforeEach(function () {
    $this->robot = RobotBatches::install($this->app);
});

/**
 * Every advisor as [company, code, name, email, active, user_id], by company and code.
 *
 * @return list<array{string, int, string, string|null, bool, int|null}>
 */
function advisorRows(): array
{
    return AdvisorRecord::query()->orderByDesc('company')->orderBy('code')->get()
        ->map(fn (AdvisorRecord $advisor): array => [
            $advisor->company->value, $advisor->code, $advisor->name, $advisor->email, $advisor->active, $advisor->user_id,
        ])
        ->all();
}

it('adds, updates and deactivates the SAP advisors of the company without touching their links', function () {
    $carlos = User::factory()->create();
    $marta = User::factory()->create();
    AdvisorRecord::factory()->linkedTo($carlos)->inactive()->create([
        'company' => CompanyCode::SGX, 'code' => 12, 'name' => 'Carlos Perez', 'email' => 'viejo@segurex.com',
    ]);
    AdvisorRecord::factory()->linkedTo($marta)->create([
        'company' => CompanyCode::SGX, 'code' => 30, 'name' => 'Marta Uribe', 'email' => 'marta.uribe@segurex.com',
    ]);
    AdvisorRecord::factory()->create([
        'company' => CompanyCode::SDC, 'code' => 31, 'name' => 'Óscar Toro', 'email' => 'oscar.toro@derivados.com',
    ]);

    $this->robot->send(Dataset::Advisors, CompanyCode::SGX, '2026-10-15', [
        RobotBatches::advisor(['slp_code' => 12, 'nombre' => ' Carlos Pérez ', 'email' => '  Carlos.Perez@Segurex.com ', 'activo' => 'Y']),
        RobotBatches::advisor(['slp_code' => 40, 'nombre' => 'Laura Gómez', 'email' => '', 'activo' => ' n ']),
        RobotBatches::advisor(['slp_code' => 41, 'nombre' => 'Jorge Henao', 'email' => null, 'activo' => 'y']),
    ]);

    expect(advisorRows())->toBe([
        ['SGX', 12, 'Carlos Pérez', 'carlos.perez@segurex.com', true, $carlos->id],
        ['SGX', 30, 'Marta Uribe', 'marta.uribe@segurex.com', false, $marta->id],
        ['SGX', 40, 'Laura Gómez', null, false, null],
        ['SGX', 41, 'Jorge Henao', null, true, null],
        ['SDC', 31, 'Óscar Toro', 'oscar.toro@derivados.com', true, null],
    ]);
});

it('gives the same result when the same advisors arrive again', function () {
    $rows = [
        RobotBatches::advisor(['slp_code' => 12]),
        RobotBatches::advisor(['slp_code' => 13, 'nombre' => 'Diana Mejía', 'email' => 'diana.mejia@segurex.com', 'activo' => 'N']),
    ];

    $this->robot->send(Dataset::Advisors, CompanyCode::SGX, '2026-10-15', $rows);
    $first = advisorRows();
    $this->robot->send(Dataset::Advisors, CompanyCode::SGX, '2026-10-16', $rows);

    expect(advisorRows())->toBe($first)
        ->and($first)->toHaveCount(2);
});

it('leaves the advisors untouched when a batch has no valid advisor', function (array $rows) {
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 12]);

    $this->robot->send(Dataset::Advisors, CompanyCode::SGX, '2026-10-15', $rows);

    expect(AdvisorRecord::query()->sole()->active)->toBeTrue();
})->with([
    'empty batch' => [[]],
    'only "no sales employee"' => [[RobotBatches::advisor(['slp_code' => -1])]],
]);

it('ignores the batches of other datasets', function () {
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 12]);

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-10-15', [RobotBatches::advisor(['slp_code' => 99])]);

    expect(AdvisorRecord::query()->pluck('code')->all())->toBe([12])
        ->and(AdvisorRecord::query()->sole()->active)->toBeTrue();
});

it('queues the import of asesores batches only', function () {
    Queue::fake();

    $this->robot->send(Dataset::Sales, CompanyCode::SGX, '2026-10-15', []);

    Queue::assertNothingPushed();

    $this->robot->send(Dataset::Advisors, CompanyCode::SGX, '2026-10-15', [RobotBatches::advisor()]);

    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ImportAdvisors::class);
});
