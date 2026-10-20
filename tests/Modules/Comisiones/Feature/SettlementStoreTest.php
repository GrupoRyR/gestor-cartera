<?php

use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementLocked;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Settlement\StaleSettlement;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Tests\Modules\Comisiones\Support\Lines;

/*
| The stored settlements: every version of every period, with its result
| serialized and fingerprinted, replaced in place while open and never
| overwritten once closed (Application/README.md, "Implement the ports").
*/

beforeEach(function () {
    $this->july = Period::of(2026, 7);
    $this->store = app(Settlements::class);

    // Code 14 of Annex 2 (July 2026): 69,915,134 over 77,241,857 = 90.5 % → 524,364; code 99 blocks.
    $this->result = fn (int $extra = 0): SettlementResult => (new SettlementCalculator)->calculate(
        new SettlementInput(
            $this->july,
            sales: [Lines::sale(14, 69_915_134 + $extra), Lines::sale(99, 1_000_000)],
            budgets: [Lines::budget(14, 77_241_857)],
        ),
        DefaultParameters::book()->forPeriod($this->july),
    );
    $this->draft = Settlement::calculate(($this->result)(), 'ana@segurex.com', new DateTimeImmutable('2026-08-03 09:00'));
    $this->saleOf14 = fn (Settlement $settlement): string => $settlement->result->code(14)?->saleCommission->amount() ?? '';
});

it('is bound to the database', function () {
    expect($this->store)->toBeInstanceOf(EloquentSettlements::class)
        ->and($this->store->latest($this->july))->toBeNull();
});

it('gives back the stored version with its result and frozen parameters', function () {
    $this->store->save($this->draft);

    $stored = $this->store->latest($this->july);

    expect($stored)->not->toBeNull()
        ->and($stored?->period)->toEqual($this->july)
        ->and($stored?->version)->toBe(1)
        ->and($stored?->revision)->toBe(1)
        ->and($stored?->status)->toBe(SettlementStatus::Draft)
        ->and($stored?->calculatedBy)->toBe('ana@segurex.com')
        ->and($stored?->calculatedAt->format('Y-m-d H:i'))->toBe('2026-08-03 09:00')
        ->and(($this->saleOf14)($stored))->toBe('524364')
        ->and($stored?->result)->toEqual($this->draft->result)
        ->and($stored?->result->parameters())->toEqual(DefaultParameters::book()->forPeriod($this->july));

    $row = SettlementRecord::query()->sole();

    expect($row->periodo)->toBe('2026-07')
        ->and($row->estado)->toBe('borrador')
        ->and($row->alertas_bloqueantes)->toBe(1)
        ->and($row->total_nomina)->toBe(Money::sum(array_map(fn (PersonSettlement $person) => $person->total, $this->draft->result->persons()))->decimal()->toScale(2)->toString())
        ->and($row->resultado_sha256)->toBe(hash('sha256', $row->resultado));
});

it('replaces an open version in place and refuses a save from an older revision', function () {
    $this->store->save($this->draft);
    $recalculated = $this->draft->recalculate(($this->result)(2_000_000), 'ana@segurex.com', new DateTimeImmutable('2026-08-04 09:00'));
    $this->store->save($recalculated);

    expect(SettlementRecord::query()->count())->toBe(1)
        ->and($this->store->latest($this->july)?->revision)->toBe(2)
        ->and(($this->saleOf14)($this->store->latest($this->july)))->toBe('539364');

    // Someone approves the revision 1 they had on screen: refused, revision 2 stays.
    $approvedOld = $this->draft->approve('beto@segurex.com', new DateTimeImmutable('2026-08-04 10:00'), 1, [
        'codigo_desconocido:99' => 'Venta de prueba.',
    ]);

    expect(fn () => $this->store->save($approvedOld))->toThrow(function (StaleSettlement $stale) {
        expect($stale->expectedRevision())->toBe(1)->and($stale->currentRevision())->toBe(2);
    });

    expect($this->store->latest($this->july)?->status)->toBe(SettlementStatus::Draft);
});

it('approves and closes a version, and never overwrites it once closed', function () {
    $this->store->save($this->draft);
    $approved = $this->draft->approve('beto@segurex.com', new DateTimeImmutable('2026-08-05 10:00'), 1, [
        'codigo_desconocido:99' => 'Venta de prueba.',
    ]);
    $this->store->save($approved);
    $closed = $approved->close('beto@segurex.com', new DateTimeImmutable('2026-08-05 11:00'));
    $this->store->save($closed);

    $stored = $this->store->latest($this->july);

    expect($stored?->status)->toBe(SettlementStatus::Closed)
        ->and($stored?->approvedBy)->toBe('beto@segurex.com')
        ->and($stored?->approvedAt?->format('Y-m-d H:i'))->toBe('2026-08-05 10:00')
        ->and($stored?->closedAt?->format('Y-m-d H:i'))->toBe('2026-08-05 11:00')
        ->and($stored?->justifications)->toBe(['codigo_desconocido:99' => 'Venta de prueba.']);

    expect(fn () => $this->store->save($approved))->toThrow(SettlementLocked::class)
        ->and(fn () => $this->store->save($closed))->toThrow(SettlementLocked::class)
        ->and($this->store->latest($this->july)?->status)->toBe(SettlementStatus::Closed)
        ->and($this->store->lastClosedPeriod())->toEqual($this->july);
});

it('keeps the complementary version apart from the closed one', function () {
    $approved = $this->draft->approve('beto@segurex.com', new DateTimeImmutable('2026-08-05 10:00'), 1, ['codigo_desconocido:99' => 'Venta de prueba.']);
    $closed = $approved->close('beto@segurex.com', new DateTimeImmutable('2026-08-05 11:00'));
    $this->store->save($this->draft);
    $this->store->save($approved);
    $this->store->save($closed);

    $complementary = $closed->complement('Nota crédito tardía de SAP', ($this->result)(-1_000_000), 'ana@segurex.com', new DateTimeImmutable('2026-09-02 08:00'));
    $this->store->save($complementary);

    $versions = $this->store->versions($this->july);

    expect(array_map(fn (Settlement $s) => [$s->version, $s->status, $s->previousVersion, $s->reason], $versions))->toBe([
        [1, SettlementStatus::Closed, null, null],
        [2, SettlementStatus::Draft, 1, 'Nota crédito tardía de SAP'],
    ])
        ->and($this->store->latest($this->july)?->version)->toBe(2)
        ->and(($this->saleOf14)($this->store->version($this->july, 1)))->toBe('524364')
        ->and(($this->saleOf14)($this->store->version($this->july, 2)))->toBe('0')
        ->and($this->store->version($this->july, 3))->toBeNull()
        ->and($this->store->record($this->july, 2)?->motivo)->toBe('Nota crédito tardía de SAP');
});

it('refuses a stored result whose fingerprint does not match', function () {
    $this->store->save($this->draft);
    SettlementRecord::query()->update(['resultado_sha256' => str_repeat('0', 64)]);

    expect(fn () => $this->store->latest($this->july))->toThrow(CorruptSettlement::class);
});
