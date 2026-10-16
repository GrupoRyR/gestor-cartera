<?php

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * The life of a commitment (docs/arquitectura.md §6.5), written by Carlos,
 * the SGX advisor of Agro Arango, on 15/10/2026 at 9:30.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->carlos = $this->people['carlos'];
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Legal]);
    $this->actingAs($this->carlos);
    $this->service = CommitmentRecords::service();
    $this->carlosRef = new UserRef($this->carlos->id, 'Carlos Pérez', 'carlos.perez@segurex.com');
});

describe('create', function () {
    it('registers a pending commitment with the client\'s advisor, logged and audited', function () {
        $commitment = $this->service->create(new NewCommitment(
            $this->agro->id,
            CarteraRecords::at('2026-10-22'),
            2_500_000,
            '  Paga con cheque de gerencia.  ',
        ));

        [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);
        [$audit] = TeamUsers::audits('core.commitment.created');

        expect($commitment)->toEqual(new Commitment(
            id: $commitment->id,
            company: CompanyCode::SGX,
            clientId: $this->agro->id,
            clientName: 'Agro Arango',
            advisorCode: 12,
            amount: 2_500_000.0,
            dueDate: CarteraRecords::at('2026-10-22'),
            status: CommitmentStatus::Pending,
            notes: 'Paga con cheque de gerencia.',
            createdBy: $this->carlosRef,
            createdAt: CarteraRecords::at('2026-10-15 09:30:00'),
        ))
            ->and(CommitmentRecords::stored($commitment)->only(['company', 'client_id', 'advisor_code', 'amount', 'status', 'created_by']))->toEqual([
                'company' => CompanyCode::SGX,
                'client_id' => $this->agro->id,
                'advisor_code' => 12,
                'amount' => '2500000.00',
                'status' => CommitmentStatus::Pending,
                'created_by' => $this->carlos->id,
            ])
            ->and($entry->summary)->toBe("Compromiso de pago por $\u{00A0}2.500.000 para el 22/10/2026")
            ->and($entry->details)->toBe('Paga con cheque de gerencia.')
            ->and($entry->user_id)->toBe($this->carlos->id)
            ->and($entry->metadata)->toEqual(['commitment_id' => $commitment->id, 'amount' => 2_500_000, 'due_date' => '2026-10-22'])
            ->and($audit->subject_type)->toBe('commitment')
            ->and($audit->subject_id)->toBe((string) $commitment->id)
            ->and($audit->user_id)->toBe($this->carlos->id)
            ->and($audit->changes)->toEqual([
                'advisor_code' => 12,
                'amount' => 2_500_000,
                'due_date' => '2026-10-22',
                'notes' => 'Paga con cheque de gerencia.',
            ])
            ->and($audit->context)->toEqual(['client_id' => $this->agro->id])
            ->and($this->agro->refresh()->last_activity_at?->toDateTimeString())->toBe('2026-10-15 09:30:00');
    });

    it('keeps the calendar date of the due date and rounds the amount to cents', function () {
        $commitment = $this->service->create(new NewCommitment(
            $this->agro->id,
            CarteraRecords::at('2026-10-22 18:45:00'),
            1_234_567.891,
            '   ',
        ));

        expect($commitment->dueDate)->toEqual(CarteraRecords::at('2026-10-22'))
            ->and($commitment->amount)->toBe(1_234_567.89)
            ->and($commitment->notes)->toBeNull()
            ->and(CommitmentRecords::logOf($this->agro, ActivityType::Commitment)[0]->details)->toBeNull();
    });

    it('rejects an amount that is not greater than zero, writing nothing', function (float $amount) {
        expect(fn () => $this->service->create(new NewCommitment($this->agro->id, CarteraRecords::at('2026-10-22'), $amount)))
            ->toThrow(InvalidArgumentException::class)
            ->and(CommitmentRecord::query()->count())->toBe(0)
            ->and(ActivityRecord::query()->count())->toBe(0)
            ->and(AuditLogRecord::query()->count())->toBe(0);
    })->with([
        'zero' => [0],
        'negative' => [-1_000],
        'less than a cent' => [0.004],
        'not a number' => [NAN],
        'infinite' => [INF],
    ]);
});

describe('update', function () {
    beforeEach(function () {
        $this->commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22', '2500000.00', ['notes' => 'Paga con cheque.']);
    });

    it('changes only the given fields, logged and audited', function () {
        $updated = $this->service->update($this->commitment->id, new CommitmentChanges(
            dueDate: CarteraRecords::at('2026-10-25'),
            amount: 3_000_000,
        ));

        [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);
        [$audit] = TeamUsers::audits('core.commitment.updated');

        expect($updated->dueDate)->toEqual(CarteraRecords::at('2026-10-25'))
            ->and($updated->amount)->toBe(3_000_000.0)
            ->and($updated->notes)->toBe('Paga con cheque.')
            ->and($updated->status)->toBe(CommitmentStatus::Pending)
            ->and($entry->summary)->toBe("Compromiso actualizado: $\u{00A0}3.000.000 para el 25/10/2026")
            ->and($entry->details)->toBe('Paga con cheque.')
            ->and($entry->user_id)->toBe($this->carlos->id)
            ->and($entry->metadata)->toEqual(['commitment_id' => $this->commitment->id, 'amount' => 3_000_000, 'due_date' => '2026-10-25'])
            ->and($audit->subject_type)->toBe('commitment')
            ->and($audit->subject_id)->toBe((string) $this->commitment->id)
            ->and($audit->changes)->toEqual([
                'amount' => ['old' => 2_500_000, 'new' => 3_000_000],
                'due_date' => ['old' => '2026-10-22', 'new' => '2026-10-25'],
            ])
            ->and($audit->context)->toEqual(['client_id' => $this->agro->id]);
    });

    it('replaces the notes but never clears them', function () {
        $this->service->update($this->commitment->id, new CommitmentChanges(notes: '  Paga en efectivo.  '));
        $blank = $this->service->update($this->commitment->id, new CommitmentChanges(notes: '   '));

        [$audit] = TeamUsers::audits('core.commitment.updated');

        expect($blank->notes)->toBe('Paga en efectivo.')
            ->and($audit->changes)->toEqual(['notes' => ['old' => 'Paga con cheque.', 'new' => 'Paga en efectivo.']])
            ->and(TeamUsers::audits('core.commitment.updated'))->toHaveCount(1)
            ->and(CommitmentRecords::logOf($this->agro, ActivityType::Commitment))->toHaveCount(1);
    });

    it('writes nothing when nothing changes', function () {
        $same = $this->service->update($this->commitment->id, new CommitmentChanges(
            dueDate: CarteraRecords::at('2026-10-22'),
            amount: 2_500_000,
            notes: 'Paga con cheque.',
        ));

        expect($same->amount)->toBe(2_500_000.0)
            ->and(ActivityRecord::query()->count())->toBe(0)
            ->and(AuditLogRecord::query()->count())->toBe(0);
    });

    it('rejects an amount that is not greater than zero', function () {
        expect(fn () => $this->service->update($this->commitment->id, new CommitmentChanges(amount: 0)))
            ->toThrow(InvalidArgumentException::class)
            ->and(CommitmentRecords::stored($this->commitment)->amount)->toBe('2500000.00');
    });

    it('changes only pending commitments', function (CommitmentStatus $status) {
        $this->commitment->update(['status' => $status]);

        expect(fn () => $this->service->update($this->commitment->id, new CommitmentChanges(amount: 3_000_000)))
            ->toThrow(DomainException::class)
            ->and(CommitmentRecords::stored($this->commitment)->amount)->toBe('2500000.00')
            ->and(AuditLogRecord::query()->count())->toBe(0);
    })->with([CommitmentStatus::Broken, CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled]);
});

describe('markFulfilled', function () {
    it('fulfils a pending or broken commitment, logged and audited', function (CommitmentStatus $status) {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-12', '2500000.00', ['status' => $status]);

        $fulfilled = $this->service->markFulfilled($commitment->id, '  Pagó en la oficina.  ');

        [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);
        [$audit] = TeamUsers::audits('core.commitment.fulfilled');

        expect($fulfilled->status)->toBe(CommitmentStatus::Fulfilled)
            ->and($fulfilled->fulfilledAt)->toEqual(CarteraRecords::at('2026-10-15 09:30:00'))
            ->and(CommitmentRecords::stored($commitment)->fulfilled_at?->toDateTimeString())->toBe('2026-10-15 09:30:00')
            ->and($entry->summary)->toBe("Compromiso cumplido: $\u{00A0}2.500.000 para el 12/10/2026")
            ->and($entry->details)->toBe('Pagó en la oficina.')
            ->and($entry->metadata)->toEqual(['commitment_id' => $commitment->id, 'amount' => 2_500_000, 'due_date' => '2026-10-12'])
            ->and($audit->subject_id)->toBe((string) $commitment->id)
            ->and($audit->changes)->toEqual(['status' => ['old' => $status->value, 'new' => 'cumplido']])
            ->and($audit->context)->toEqual(['client_id' => $this->agro->id, 'note' => 'Pagó en la oficina.']);
    })->with([CommitmentStatus::Pending, CommitmentStatus::Broken]);

    it('needs no note', function () {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22');

        $this->service->markFulfilled($commitment->id, '  ');

        expect(CommitmentRecords::logOf($this->agro, ActivityType::Commitment)[0]->details)->toBeNull()
            ->and(TeamUsers::audits('core.commitment.fulfilled')[0]->context)->toEqual(['client_id' => $this->agro->id]);
    });

    it('refuses a commitment that is already final', function (CommitmentStatus $status) {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22', attributes: ['status' => $status]);

        expect(fn () => $this->service->markFulfilled($commitment->id))->toThrow(DomainException::class)
            ->and(CommitmentRecords::stored($commitment)->status)->toBe($status)
            ->and(ActivityRecord::query()->count())->toBe(0);
    })->with([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled]);
});

describe('cancel', function () {
    it('voids a pending or broken commitment with its reason, logged and audited', function (CommitmentStatus $status) {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22', attributes: ['status' => $status]);

        $cancelled = $this->service->cancel($commitment->id, '  Se renegoció a 60 días.  ');

        $stored = CommitmentRecords::stored($commitment);
        [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);
        [$audit] = TeamUsers::audits('core.commitment.cancelled');

        expect($cancelled->status)->toBe(CommitmentStatus::Cancelled)
            ->and($stored->cancellation_reason)->toBe('Se renegoció a 60 días.')
            ->and($stored->cancelled_at?->toDateTimeString())->toBe('2026-10-15 09:30:00')
            ->and($entry->summary)->toBe('Compromiso anulado: Se renegoció a 60 días.')
            ->and($entry->metadata)->toEqual(['commitment_id' => $commitment->id, 'amount' => 1_000_000, 'due_date' => '2026-10-22'])
            ->and($audit->changes)->toEqual(['status' => ['old' => $status->value, 'new' => 'anulado']])
            ->and($audit->context)->toEqual(['client_id' => $this->agro->id, 'reason' => 'Se renegoció a 60 días.']);
    })->with([CommitmentStatus::Pending, CommitmentStatus::Broken]);

    it('keeps a long reason whole and cuts only the log line', function () {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22');
        $reason = str_repeat('Renegociado con tesorería. ', 20);

        $this->service->cancel($commitment->id, $reason);

        $summary = CommitmentRecords::logOf($this->agro, ActivityType::Commitment)[0]->summary;

        expect(CommitmentRecords::stored($commitment)->cancellation_reason)->toBe(trim($reason))
            ->and($summary)->toStartWith('Compromiso anulado: Renegociado con tesorería.')
            ->and($summary)->toEndWith('…')
            ->and(mb_strlen($summary))->toBeLessThanOrEqual(255)
            ->and(TeamUsers::audits('core.commitment.cancelled')[0]->context['reason'])->toBe(trim($reason));
    });

    it('needs a reason', function () {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22');

        expect(fn () => $this->service->cancel($commitment->id, " \n "))->toThrow(InvalidArgumentException::class)
            ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
    });

    it('refuses a commitment that is already final', function (CommitmentStatus $status) {
        $commitment = CommitmentRecords::commitment($this->agro, $this->carlos, '2026-10-22', attributes: ['status' => $status]);

        expect(fn () => $this->service->cancel($commitment->id, 'Error'))->toThrow(DomainException::class)
            ->and(CommitmentRecords::stored($commitment)->status)->toBe($status);
    })->with([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled]);
});
