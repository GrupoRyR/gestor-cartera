<?php

use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * Who changes the status of a commitment (docs/arquitectura.md §6.5): its
 * writer (the SGX advisor of the client, the SDC analyst) and, in both
 * companies, the portfolio analyst and manager, who only mark it fulfilled
 * or broken. Agro Arango is an overdue SGX client of advisor 12 (Carlos);
 * Granos Giraldo, an overdue SDC client of advisor 12 (Hernán).
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    $this->granos = CarteraRecords::client('Granos Giraldo', [
        'company' => CompanyCode::SDC,
        'advisor_code' => 12,
        'status' => ClientStatus::Overdue,
    ]);
});

dataset('portfolio team', [
    'the analyst in SGX' => ['mariaPaula', 'agro'],
    'the manager in SGX' => ['miguel', 'agro'],
    'the manager in SDC' => ['miguel', 'granos'],
]);

it('lets the portfolio team fulfil a commitment the advisor created, logged and audited as theirs', function (string $who, string $client) {
    $commitment = CommitmentRecords::commitment($this->{$client}, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people[$who]);

    $fulfilled = CommitmentRecords::service()->markFulfilled($commitment->id, 'Pagó por transferencia.');

    [$entry] = CommitmentRecords::logOf($this->{$client}, ActivityType::Commitment);
    [$audit] = TeamUsers::audits('core.commitment.fulfilled');

    expect($fulfilled->status)->toBe(CommitmentStatus::Fulfilled)
        ->and($entry->user_id)->toBe($this->people[$who]->id)
        ->and($entry->details)->toBe('Pagó por transferencia.')
        ->and($audit->user_id)->toBe($this->people[$who]->id);
})->with('portfolio team');

it('lets the portfolio team mark a pending commitment broken, logged and audited as theirs', function (string $who, string $client) {
    $commitment = CommitmentRecords::commitment($this->{$client}, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people[$who]);

    $broken = CommitmentRecords::service()->markBroken($commitment->id, '  No contesta y no pagará esta semana.  ');

    [$entry] = CommitmentRecords::logOf($this->{$client}, ActivityType::Commitment);
    [$audit] = TeamUsers::audits('core.commitment.broken');

    expect($broken->status)->toBe(CommitmentStatus::Broken)
        ->and(CommitmentRecords::stored($commitment)->broken_at?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($entry->summary)->toBe('Compromiso incumplido: vencía el 20/10/2026')
        ->and($entry->details)->toBe('No contesta y no pagará esta semana.')
        ->and($entry->user_id)->toBe($this->people[$who]->id)
        ->and($audit->user_id)->toBe($this->people[$who]->id)
        ->and($audit->changes)->toEqual(['status' => ['old' => 'pendiente', 'new' => 'incumplido']])
        ->and($audit->context)->toEqual([
            'client_id' => $this->{$client}->id,
            'due_date' => '2026-10-20',
            'note' => 'No contesta y no pagará esta semana.',
        ])
        ->and($this->{$client}->refresh()->status)->toBe(ClientStatus::Defaulted);
})->with('portfolio team');

it('marks a commitment broken without a note', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['miguel']);

    CommitmentRecords::service()->markBroken($commitment->id, '  ');

    expect(CommitmentRecords::logOf($this->agro, ActivityType::Commitment)[0]->details)->toBeNull()
        ->and(TeamUsers::audits('core.commitment.broken')[0]->context)->toEqual(['client_id' => $this->agro->id, 'due_date' => '2026-10-20']);
});

it('still fulfils a broken commitment, a late payment', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['mariaPaula']);
    $service = CommitmentRecords::service();

    $service->markBroken($commitment->id);

    expect($service->markFulfilled($commitment->id)->status)->toBe(CommitmentStatus::Fulfilled);
});

it('marks broken only a pending commitment', function (CommitmentStatus $status) {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20', attributes: ['status' => $status]);
    $this->actingAs($this->people['mariaPaula']);

    expect(fn () => CommitmentRecords::service()->markBroken($commitment->id))->toThrow(DomainException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe($status)
        ->and(TeamUsers::audits('core.commitment.broken'))->toBe([]);
})->with([CommitmentStatus::Broken, CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled]);

it('never lets the portfolio team create, edit or cancel an SGX commitment', function (string $who) {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people[$who]);
    $service = CommitmentRecords::service();

    expect(fn () => $service->create(new NewCommitment($this->agro->id, CarteraRecords::at('2026-10-22'), 2_500_000)))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->update($commitment->id, new CommitmentChanges(dueDate: CarteraRecords::at('2026-10-30'))))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->update($commitment->id, new CommitmentChanges(amount: 5)))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->cancel($commitment->id, 'Error'))->toThrow(AuthorizationException::class)
        ->and(CommitmentRecords::stored($commitment)->amount)->toBe('1000000.00')
        ->and(CommitmentRecords::stored($commitment)->due_date->toDateString())->toBe('2026-10-20')
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
})->with(['mariaPaula', 'miguel']);

it('lets the SGX advisor of the client mark its commitment broken', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['carlos']);

    expect(CommitmentRecords::service()->markBroken($commitment->id)->status)->toBe(CommitmentStatus::Broken);
});

it('refuses the status change to a superadmin and a sales manager of the advisor\'s team', function (Role $role) {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $user = CoreUsers::create($role, CompanyCode::SGX);
    AdvisorRecord::teamOf($user)->attach(AdvisorRecord::query()->where('company', CompanyCode::SGX)->where('code', 12)->sole());
    $this->actingAs($user);
    $service = CommitmentRecords::service();

    expect(fn () => $service->markFulfilled($commitment->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->markBroken($commitment->id))->toThrow(AuthorizationException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(AuditLogRecord::query()->count())->toBe(0);
})->with([Role::SuperAdmin, Role::SalesManager]);

it('refuses the status change to the SDC advisor of the client', function () {
    $commitment = CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-20');
    $this->actingAs($this->people['hernan']);
    $service = CommitmentRecords::service();

    expect(fn () => $service->markFulfilled($commitment->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->markBroken($commitment->id))->toThrow(AuthorizationException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
});

it('hides the commitments of another advisor\'s client from an advisor', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['diana']);
    $service = CommitmentRecords::service();

    expect(fn () => $service->markFulfilled($commitment->id))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->markBroken($commitment->id))->toThrow(RecordNotFound::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
});
