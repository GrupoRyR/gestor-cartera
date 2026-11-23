<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * Recording a partial payment (abono, docs/arquitectura.md §6.5): the
 * client paid part of a commitment and the rest stays owed. Agro Arango is
 * a committed SGX client of advisor 12 (Carlos) with an item 30 days past
 * due; Granos Giraldo, an overdue SDC client of advisor 12 (Hernán).
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Committed]);
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14'), $this->agro, '1500000.00', 30);
    $this->granos = CarteraRecords::client('Granos Giraldo', [
        'company' => CompanyCode::SDC,
        'advisor_code' => 12,
        'status' => ClientStatus::Overdue,
    ]);
});

it('records a partial payment of a pending commitment, logged, audited and moving the client', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20', '2500000.00');
    $this->actingAs($this->people['carlos']);

    $paid = CommitmentRecords::service()->markPartiallyPaid($commitment->id, 1_000_000, '  Abonó en efectivo.  ');

    [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);
    [$audit] = TeamUsers::audits('core.commitment.partially_paid');

    expect($paid->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and($paid->paidAmount)->toBe(1_000_000.0)
        ->and($paid->partiallyPaidAt?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($paid->remaining())->toBe(1_500_000.0)
        ->and($paid->fulfilledAt)->toBeNull()
        ->and(CommitmentRecords::stored($commitment)->paid_amount)->toBe('1000000.00')
        ->and($entry->summary)->toBe("Compromiso abonado: $\u{00A0}1.000.000 de $\u{00A0}2.500.000; queda $\u{00A0}1.500.000")
        ->and($entry->details)->toBe('Abonó en efectivo.')
        ->and($entry->user_id)->toBe($this->people['carlos']->id)
        ->and($entry->metadata)->toEqual([
            'commitment_id' => $commitment->id,
            'amount' => 2_500_000,
            'due_date' => '2026-10-20',
            'paid_amount' => 1_000_000,
            'remaining' => 1_500_000,
        ])
        ->and($audit->subject_id)->toBe((string) $commitment->id)
        ->and($audit->user_id)->toBe($this->people['carlos']->id)
        ->and($audit->changes)->toEqual([
            'status' => ['old' => 'pendiente', 'new' => 'abonado'],
            'paid_amount' => ['old' => null, 'new' => 1_000_000],
        ])
        ->and($audit->context)->toEqual(['client_id' => $this->agro->id, 'note' => 'Abonó en efectivo.'])
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(CommitmentRecords::logOf($this->agro, ActivityType::StatusChange))->toHaveCount(1);
});

it('records a partial payment of a broken commitment, without a note', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-10', attributes: ['status' => CommitmentStatus::Broken]);
    $this->agro->update(['status' => ClientStatus::Defaulted]);
    $this->actingAs($this->people['mariaPaula']);

    $paid = CommitmentRecords::service()->markPartiallyPaid($commitment->id, 400_000.5, '  ');

    [$audit] = TeamUsers::audits('core.commitment.partially_paid');

    expect($paid->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and(CommitmentRecords::stored($commitment)->paid_amount)->toBe('400000.50')
        ->and($paid->remaining())->toBe(599_999.5)
        ->and(CommitmentRecords::logOf($this->agro, ActivityType::Commitment)[0]->details)->toBeNull()
        ->and($audit->changes['status'])->toEqual(['old' => 'incumplido', 'new' => 'abonado'])
        ->and($audit->context)->toEqual(['client_id' => $this->agro->id])
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Overdue);
});

it('refuses a partial payment of a final commitment', function (CommitmentStatus $status) {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20', attributes: ['status' => $status]);
    $this->actingAs($this->people['mariaPaula']);

    expect(fn () => CommitmentRecords::service()->markPartiallyPaid($commitment->id, 100))->toThrow(DomainException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe($status)
        ->and(TeamUsers::audits('core.commitment.partially_paid'))->toBe([])
        ->and(NoticeRecord::query()->count())->toBe(0);
})->with([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled, CommitmentStatus::PartiallyPaid]);

it('takes only a paid amount above zero and below the commitment amount, in cents', function (float $amount) {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20', '1000000.00');
    $this->actingAs($this->people['mariaPaula']);

    expect(fn () => CommitmentRecords::service()->markPartiallyPaid($commitment->id, $amount))->toThrow(InvalidArgumentException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending)
        ->and(CommitmentRecords::stored($commitment)->paid_amount)->toBeNull()
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.commitment.partially_paid'))->toBe([])
        ->and(NoticeRecord::query()->count())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'zero after rounding to cents' => [0.004],
    'the whole amount' => [1_000_000],
    'the whole amount after rounding to cents' => [999_999.996],
    'more than the amount' => [2_000_000],
    'not a number' => [NAN],
    'infinite' => [INF],
]);

dataset('who records a partial payment', [
    'the SGX advisor of the client' => ['carlos', 'agro'],
    'the analyst in SGX' => ['mariaPaula', 'agro'],
    'the analyst in SDC' => ['mariaPaula', 'granos'],
    'the manager in SGX' => ['miguel', 'agro'],
    'the manager in SDC' => ['miguel', 'granos'],
]);

it('lets the writer and the portfolio team record a partial payment, as theirs', function (string $who, string $client) {
    $commitment = CommitmentRecords::commitment($this->{$client}, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people[$who]);

    $paid = CommitmentRecords::service()->markPartiallyPaid($commitment->id, 250_000);

    expect($paid->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and(CommitmentRecords::logOf($this->{$client}, ActivityType::Commitment)[0]->user_id)->toBe($this->people[$who]->id)
        ->and(TeamUsers::audits('core.commitment.partially_paid')[0]->user_id)->toBe($this->people[$who]->id);
})->with('who records a partial payment');

it('refuses a partial payment to an admin, a superadmin and a sales manager of the advisor\'s team', function (Role $role) {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $user = CoreUsers::create($role, CompanyCode::SGX);
    AdvisorRecord::teamOf($user)->attach(AdvisorRecord::query()->where('company', CompanyCode::SGX)->where('code', 12)->sole());
    $this->actingAs($user);

    expect(fn () => CommitmentRecords::service()->markPartiallyPaid($commitment->id, 100))->toThrow(AuthorizationException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(AuditLogRecord::query()->count())->toBe(0);
})->with([Role::Admin, Role::SuperAdmin, Role::SalesManager]);

it('refuses a partial payment to the SDC advisor of the client', function () {
    $commitment = CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-20');
    $this->actingAs($this->people['hernan']);

    expect(fn () => CommitmentRecords::service()->markPartiallyPaid($commitment->id, 100))->toThrow(AuthorizationException::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
});

it('hides the commitments of another advisor\'s client before checking the amount', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['diana']);
    $service = CommitmentRecords::service();

    expect(fn () => $service->markPartiallyPaid($commitment->id, 100))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->markPartiallyPaid($commitment->id, 0))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->markPartiallyPaid(404, 100))->toThrow(RecordNotFound::class)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
});
