<?php

use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\ClientStatusService;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Portfolio\EloquentClientStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Modules\Core\Support\ActivityRecords;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * Agro Arango is an overdue client of Segurex, of SAP advisor 12.
 */
beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->client = CarteraRecords::client('Agro Arango', [
        'advisor_code' => 12,
        'status' => ClientStatus::Overdue,
        'balance' => '1000.00',
        'overdue' => '1000.00',
        'max_days_overdue' => 20,
    ]);
    $this->service = fn (): ClientStatusService => app(ClientStatusService::class);
});

it('is the ClientStatusService of the container', function () {
    expect(app(ClientStatusService::class))->toBeInstanceOf(EloquentClientStatusService::class);
});

it('lets portfolio managers and analysts change a status by hand, logged and audited', function (Role $role) {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $user = TeamUsers::member('María Paula Ríos', 'maria.rios@segurex.com', $role, [CompanyCode::SGX]);
    $this->actingAs($user);

    $summary = ($this->service)()->change($this->client->id, ClientStatus::Legal, '  Pasa a cobro jurídico.  ');

    $this->client->refresh();
    [$activity] = ActivityRecords::logger()->forClient($this->client->id);
    [$audit] = TeamUsers::audits('core.client.status_changed');

    expect($summary->id)->toBe($this->client->id)
        ->and($summary->name)->toBe('Agro Arango')
        ->and($summary->status)->toBe(ClientStatus::Legal)
        ->and($summary->lastActivityAt?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($this->client->status)->toBe(ClientStatus::Legal)
        ->and($this->client->status_changed_at?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($activity->type)->toBe(ActivityType::StatusChange)
        ->and($activity->summary)->toBe('De «Vencida» a «Jurídico»')
        ->and($activity->details)->toBe('Pasa a cobro jurídico.')
        ->and($activity->user)->toEqual(new UserRef($user->id, 'María Paula Ríos', 'maria.rios@segurex.com'))
        ->and($activity->metadata)->toEqual(['from' => 'vencida', 'to' => 'juridico'])
        ->and($audit->subject_type)->toBe('client')
        ->and($audit->subject_id)->toBe((string) $this->client->id)
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->changes)->toEqual(['status' => ['old' => 'vencida', 'new' => 'juridico']])
        ->and($audit->context)->toEqual(['source' => 'manual']);
})->with([Role::PortfolioManager, Role::PortfolioAnalyst]);

it('logs no details without a note', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX));

    ($this->service)()->change($this->client->id, ClientStatus::InProgress, '   ');

    expect(ActivityRecord::query()->sole()->details)->toBeNull();
});

it('refuses every other role, even on their own clients', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX);
    TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $user);
    $this->actingAs($user);

    expect(fn () => ($this->service)()->change($this->client->id, ClientStatus::InProgress))
        ->toThrow(AuthorizationException::class)
        ->and($this->client->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.client.status_changed'))->toBe([]);
})->with([Role::SalesAdvisor, Role::SalesManager, Role::Admin, Role::Treasury]);

it('refuses guests', function () {
    expect(fn () => ($this->service)()->change($this->client->id, ClientStatus::InProgress))
        ->toThrow(AuthorizationException::class);
});

it('refuses a client that is missing, deleted or out of scope', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioManager, CompanyCode::SDC));

    expect(fn () => ($this->service)()->change($this->client->id, ClientStatus::InProgress))
        ->toThrow(RecordNotFound::class, "No client with id {$this->client->id} exists in the current scope.");

    $this->actingAs(CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX));
    $this->client->delete();

    expect(fn () => ($this->service)()->change($this->client->id, ClientStatus::InProgress))->toThrow(RecordNotFound::class)
        ->and(fn () => ($this->service)()->change(999, ClientStatus::InProgress))->toThrow(RecordNotFound::class)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.client.status_changed'))->toBe([]);
});

it('changes nothing when the status is the same', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX));

    $summary = ($this->service)()->change($this->client->id, ClientStatus::Overdue, 'Sin cambios');

    expect($summary->status)->toBe(ClientStatus::Overdue)
        ->and($this->client->refresh()->status_changed_at)->toBeNull()
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.client.status_changed'))->toBe([]);
});

it('keeps a manual status while the client owes and recomputes an automatic one', function (ClientStatus $chosen, ClientStatus $afterSnapshot) {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX));
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15'), $this->client, '1000.00', 20);

    ($this->service)()->change($this->client->id, $chosen);

    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-16'), $this->client, '1000.00', 21);
    app(RecomputeClientStatuses::class)->forCompany(CompanyCode::SGX);

    expect($this->client->refresh()->status)->toBe($afterSnapshot);
})->with([
    'manual' => [ClientStatus::Legal, ClientStatus::Legal],
    'automatic' => [ClientStatus::Current, ClientStatus::Overdue],
]);
