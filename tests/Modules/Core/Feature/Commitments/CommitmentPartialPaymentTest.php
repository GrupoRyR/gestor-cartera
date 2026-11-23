<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Illuminate\Support\Facades\Schema;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;

/*
 * Partially paid commitments (abonados, docs/arquitectura.md §6.5): the
 * stored data and how it reaches the contract. María Paula, the portfolio
 * analyst, reads a commitment of Agro Arango, an SGX client of advisor 12.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    $this->actingAs($this->people['mariaPaula']);
});

it('stores the paid amount and the moment of the partial payment', function () {
    expect(Schema::hasColumns('commitments', ['paid_amount', 'partially_paid_at']))->toBeTrue();
});

it('reads a partially paid commitment with its paid amount and remaining balance', function () {
    $stored = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-12', '2500000.00', [
        'status' => CommitmentStatus::PartiallyPaid,
        'paid_amount' => '1000000.50',
        'partially_paid_at' => CarteraRecords::at('2026-10-14 16:00:00'),
    ]);

    $commitment = CommitmentRecords::service()->find($stored->id);

    expect($commitment?->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and($commitment?->paidAmount)->toBe(1_000_000.5)
        ->and($commitment?->partiallyPaidAt)->toEqual(CarteraRecords::at('2026-10-14 16:00:00'))
        ->and($commitment?->remaining())->toBe(1_499_999.5)
        ->and($commitment?->fulfilledAt)->toBeNull();
});

it('reads no paid amount on the other commitments', function () {
    $stored = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');

    $commitment = CommitmentRecords::service()->find($stored->id);

    expect($commitment?->paidAmount)->toBeNull()
        ->and($commitment?->partiallyPaidAt)->toBeNull()
        ->and($commitment?->remaining())->toBeNull();
});

it('builds partially paid commitments in the factory', function () {
    $record = CommitmentRecord::factory()->partiallyPaid(300_000)->create(['amount' => '1000000.00']);

    expect($record->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and($record->paid_amount)->toBe('300000.00')
        ->and($record->partially_paid_at?->toDateTimeString())->toBe('2026-10-15 09:30:00');
});
