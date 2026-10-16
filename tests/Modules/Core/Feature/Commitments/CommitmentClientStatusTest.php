<?php

use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * How commitments move the client's status (CommitmentRules, README
 * «Compromisos de pago»). Agro Arango is an SGX client of advisor 12
 * (Carlos), who writes every commitment on 15/10/2026 at 9:30. SGX counts an
 * invoice as overdue 15 days after its due date.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->carlos = $this->people['carlos'];
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Overdue, 'balance' => '1000.00']);
    $this->actingAs($this->carlos);
    $this->service = CommitmentRecords::service();
    $this->create = fn (string $dueDate = '2026-10-22') => $this->service->create(
        new NewCommitment($this->agro->id, CarteraRecords::at($dueDate), 2_500_000),
    );
});

it('moves the client to committed when a commitment is created, logged and audited', function () {
    $commitment = ($this->create)();

    $this->agro->refresh();
    [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::StatusChange);
    [$audit] = TeamUsers::audits('core.client.status_changed');

    expect($this->agro->status)->toBe(ClientStatus::Committed)
        ->and($this->agro->status_changed_at?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($entry->summary)->toBe('Por el compromiso de pago: de «Vencida» a «Compromiso»')
        ->and($entry->user_id)->toBe($this->carlos->id)
        ->and($entry->metadata)->toEqual(['from' => 'vencida', 'to' => 'compromiso', 'commitment_id' => $commitment->id])
        ->and($audit->subject_type)->toBe('client')
        ->and($audit->subject_id)->toBe((string) $this->agro->id)
        ->and($audit->user_id)->toBe($this->carlos->id)
        ->and($audit->changes)->toEqual(['status' => ['old' => 'vencida', 'new' => 'compromiso']])
        ->and($audit->context)->toEqual(['source' => 'commitment', 'commitment_id' => $commitment->id]);
});

it('keeps a client in legal or already committed when a commitment is created', function (ClientStatus $status) {
    $this->agro->update(['status' => $status]);

    ($this->create)();

    expect($this->agro->refresh()->status)->toBe($status)
        ->and($this->agro->status_changed_at)->toBeNull()
        ->and(CommitmentRecords::logOf($this->agro, ActivityType::StatusChange))->toBe([])
        ->and(TeamUsers::audits('core.client.status_changed'))->toBe([]);
})->with([ClientStatus::Legal, ClientStatus::Committed]);

it('hands the client back to its automatic status from the latest snapshot when the commitment closes', function (string $close, string $balance, int $daysOverdue, ClientStatus $expected) {
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-01'), $this->agro, '9999.00', 60);
    $latest = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14');

    if ($balance !== '0.00') {
        CarteraRecords::invoice($latest, $this->agro, $balance, $daysOverdue);
    }

    $commitment = ($this->create)();
    $close === 'fulfil'
        ? $this->service->markFulfilled($commitment->id)
        : $this->service->cancel($commitment->id, 'Se renegoció');

    $this->agro->refresh();
    $entries = CommitmentRecords::logOf($this->agro, ActivityType::StatusChange);
    $audits = TeamUsers::audits('core.client.status_changed');

    expect($this->agro->status)->toBe($expected)
        ->and($entries)->toHaveCount(2)
        ->and($entries[1]->summary)->toBe("Por el compromiso de pago: de «Compromiso» a «{$expected->label()}»")
        ->and($entries[1]->metadata)->toEqual(['from' => 'compromiso', 'to' => $expected->value, 'commitment_id' => $commitment->id])
        ->and($audits)->toHaveCount(2)
        ->and($audits[1]->changes)->toEqual(['status' => ['old' => 'compromiso', 'new' => $expected->value]])
        ->and($audits[1]->context)->toEqual(['source' => 'commitment', 'commitment_id' => $commitment->id]);
})->with([
    'fulfilled, more than the grace days past due' => ['fulfil', '1000.00', 16, ClientStatus::Overdue],
    'fulfilled, still inside the grace days' => ['fulfil', '1000.00', 15, ClientStatus::DueSoon],
    'fulfilled, not due yet' => ['fulfil', '1000.00', -10, ClientStatus::Current],
    'fulfilled, nothing owed' => ['fulfil', '0.00', 0, ClientStatus::PaymentReceived],
    'cancelled, past due' => ['cancel', '1000.00', 30, ClientStatus::Overdue],
]);

it('keeps the client committed while another commitment is pending', function () {
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14'), $this->agro, '1000.00', 30);
    $first = ($this->create)('2026-10-22');
    $second = ($this->create)('2026-10-29');

    $this->service->markFulfilled($first->id);

    expect($this->agro->refresh()->status)->toBe(ClientStatus::Committed)
        ->and(TeamUsers::audits('core.client.status_changed'))->toHaveCount(1);

    $this->service->cancel($second->id, 'Ya pagó todo');

    expect($this->agro->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(TeamUsers::audits('core.client.status_changed'))->toHaveCount(2);
});

it('sends the client back to defaulted while another commitment is still broken', function () {
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14'), $this->agro, '1000.00', 30);
    $broken = ($this->create)('2026-10-22');
    CommitmentRecord::query()->whereKey($broken->id)->update(['status' => CommitmentStatus::Broken->value, 'broken_at' => now()]);
    $this->agro->update(['status' => ClientStatus::Defaulted]);

    $renewed = ($this->create)('2026-10-29');

    expect($this->agro->refresh()->status)->toBe(ClientStatus::Committed);

    $this->service->markFulfilled($renewed->id);

    expect($this->agro->refresh()->status)->toBe(ClientStatus::Defaulted);
});

it('keeps a status someone chose after the commitment was created', function (ClientStatus $status) {
    $commitment = ($this->create)();
    $this->agro->update(['status' => $status]);

    $this->service->markFulfilled($commitment->id);

    expect($this->agro->refresh()->status)->toBe($status)
        ->and(TeamUsers::audits('core.client.status_changed'))->toHaveCount(1);
})->with([ClientStatus::Legal, ClientStatus::InProgress, ClientStatus::PaymentReceived]);

it('does not touch the client status when a commitment is edited', function () {
    $commitment = ($this->create)();
    $this->agro->update(['status' => ClientStatus::InProgress]);

    $this->service->update($commitment->id, new CommitmentChanges(amount: 3_000_000));

    expect($this->agro->refresh()->status)->toBe(ClientStatus::InProgress)
        ->and(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
});
