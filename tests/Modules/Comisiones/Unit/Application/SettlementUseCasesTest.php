<?php

use App\Modules\Comisiones\Application\ApproveSettlement;
use App\Modules\Comisiones\Application\CalculateSettlement;
use App\Modules\Comisiones\Application\CloseSettlement;
use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Application\RegisterComplementarySettlement;
use App\Modules\Comisiones\Application\SettlementNotFound;
use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementLocked;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Settlement\StaleSettlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\FixedInputs;
use Tests\Modules\Comisiones\Support\FixedParameters;
use Tests\Modules\Comisiones\Support\InMemorySettlements;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Params;
use Tests\Modules\Comisiones\Support\Settle;

/*
| The use cases the infrastructure and the screens call: each one reads the
| ports, runs the engine on the parameters in force for the period and
| stores the new version of the settlement.
*/

beforeEach(function () {
    $this->july = Period::of(2026, 7);
    $this->inputs = new FixedInputs;
    $this->parameters = new FixedParameters;
    $this->settlements = new InMemorySettlements;

    $this->calculate = new CalculateSettlement($this->inputs, $this->parameters, $this->settlements);
    $this->approve = new ApproveSettlement($this->settlements);
    $this->close = new CloseSettlement($this->settlements);
    $this->complement = new RegisterComplementarySettlement($this->inputs, $this->parameters, $this->settlements);

    // Code 14 of Annex 2 (July 2026): 69,915,134 over 77,241,857 = 90.5 % → 524,364.
    $this->annex = fn (array $extraSales = []) => new SettlementInput(
        $this->july,
        sales: [Lines::sale(14, 69_915_134), ...$extraSales],
        budgets: [Lines::budget(14, 77_241_857)],
    );
    $this->saleCommissionOf14 = fn (Settlement $settlement) => Settle::code($settlement->result, 14)->saleCommission->amount();
});

describe('recalculating an open period and registering a complementary settlement on a closed one (§6)', function () {
    it('runs the whole monthly flow and keeps every version', function () {
        // §6 step 4: the analyst calculates the draft; an unknown code 99 blocks it.
        $this->inputs->put(($this->annex)([Lines::sale(99, 1_000_000)]));
        $draft = $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-08-03 09:00'));

        expect($draft->version)->toBe(1)
            ->and($draft->status)->toBe(SettlementStatus::Draft)
            ->and(($this->saleCommissionOf14)($draft))->toBe('524364')
            ->and(array_map(fn (Alert $alert) => $alert->key(), $draft->result->blockingAlerts()))->toBe(['codigo_desconocido:99']);

        // Late SAP lines arrive while the period is open: recalculated in the same version.
        $this->inputs->put(($this->annex)([Lines::sale(99, 1_000_000), Lines::sale(14, 2_000_000)]));
        $recalculated = $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-08-04 09:00'));

        expect($recalculated->version)->toBe(1)
            ->and($recalculated->status)->toBe(SettlementStatus::Draft)
            ->and(($this->saleCommissionOf14)($recalculated))->toBe('539364')
            ->and($this->settlements->versions($this->july))->toHaveCount(1);

        // §6 steps 5 and 6: another user approves with the blocking alert justified, then closes.
        expect($recalculated->revision)->toBe(2);

        $this->approve->handle($this->july, 'beto', new DateTimeImmutable('2026-08-05 10:00'), $recalculated->revision, [
            'codigo_desconocido:99' => 'Código 99 es una venta de prueba de SAP; se excluye.',
        ]);
        $closed = $this->close->handle($this->july, 'beto', new DateTimeImmutable('2026-08-05 11:00'));

        expect($closed->status)->toBe(SettlementStatus::Closed)
            ->and($this->settlements->latest($this->july))->toBe($closed);

        // A closed period is never recalculated.
        expect(fn () => $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-09-01 08:00')))
            ->toThrow(SettlementLocked::class);

        // §6 step 7: the correction is a complementary version with new data and a reason.
        $this->inputs->put(($this->annex)([Lines::sale(14, 7_326_723)]));
        $complementary = $this->complement->handle(
            $this->july,
            'carla',
            'La venta del código 99 era del código 14.',
            new DateTimeImmutable('2026-09-02 08:00'),
        );

        expect($complementary->version)->toBe(2)
            ->and($complementary->previousVersion)->toBe(1)
            ->and($complementary->status)->toBe(SettlementStatus::Draft)
            ->and($complementary->reason)->toBe('La venta del código 99 era del código 14.')
            ->and(($this->saleCommissionOf14)($complementary))->toBe('579314')
            ->and($complementary->result->hasBlockingAlerts())->toBeFalse();

        // The complementary draft is the open one now: calculating recalculates it.
        $this->inputs->put(($this->annex)([Lines::sale(14, 10_084_866)]));
        $reopened = $this->calculate->handle($this->july, 'carla', new DateTimeImmutable('2026-09-03 08:00'));

        expect($reopened->version)->toBe(2)
            ->and($reopened->revision)->toBe(2);

        $this->approve->handle($this->july, 'beto', new DateTimeImmutable('2026-09-03 10:00'), $reopened->revision);
        $this->close->handle($this->july, 'beto', new DateTimeImmutable('2026-09-03 11:00'));

        [$first, $second] = $this->settlements->versions($this->july);

        expect($this->settlements->versions($this->july))->toHaveCount(2)
            ->and($first)->toBe($closed)
            ->and($first->status)->toBe(SettlementStatus::Closed)
            ->and(($this->saleCommissionOf14)($first))->toBe('539364')
            ->and($first->justifications)->toBe(['codigo_desconocido:99' => 'Código 99 es una venta de prueba de SAP; se excluye.'])
            ->and($second->version)->toBe(2)
            ->and($second->status)->toBe(SettlementStatus::Closed)
            ->and($second->reason)->toBe('La venta del código 99 era del código 14.')
            ->and($second->calculatedBy)->toBe('carla')
            ->and(($this->saleCommissionOf14)($second))->toBe('600000');
    });
});

describe('a period without settlement', function () {
    it('cannot be approved, closed or complemented before it is calculated', function (Closure $step) {
        expect(fn () => $step($this))->toThrow(SettlementNotFound::class, 'There is no settlement of 2026-07');
    })->with([
        'approve' => [fn ($test) => $test->approve->handle($test->july, 'beto', new DateTimeImmutable, 1)],
        'close' => [fn ($test) => $test->close->handle($test->july, 'beto', new DateTimeImmutable)],
        'complement' => [fn ($test) => $test->complement->handle($test->july, 'carla', 'Corrección.', new DateTimeImmutable)],
    ]);
});

describe('approving only what the approver reviewed (§6 step 5, §10)', function () {
    beforeEach(function () {
        $this->inputs->put(($this->annex)());
        $this->reviewed = $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-08-03 09:00'));
    });

    it('refuses the approval when the analyst recalculated after the review, and stores nothing', function () {
        // The approver reviews revision 1; late SAP lines arrive and the analyst recalculates.
        $this->inputs->put(($this->annex)([Lines::sale(14, 2_000_000)]));
        $recalculated = $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-08-04 09:00'));

        expect(fn () => $this->approve->handle($this->july, 'beto', new DateTimeImmutable('2026-08-04 10:00'), $this->reviewed->revision))
            ->toThrow(StaleSettlement::class, 'is at revision 2');

        expect($this->settlements->latest($this->july))->toBe($recalculated)
            ->and($this->settlements->latest($this->july)?->status)->toBe(SettlementStatus::Draft);
    });

    it('approves once the approver reviews the current revision', function () {
        $this->inputs->put(($this->annex)([Lines::sale(14, 2_000_000)]));
        $recalculated = $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-08-04 09:00'));

        $approved = $this->approve->handle($this->july, 'beto', new DateTimeImmutable('2026-08-04 10:00'), $recalculated->revision);

        expect($approved->status)->toBe(SettlementStatus::Approved)
            ->and($approved->revision)->toBe(2)
            ->and(($this->saleCommissionOf14)($approved))->toBe('539364')
            ->and($this->settlements->latest($this->july))->toBe($approved);
    });
});

describe('the Settlements port refuses to go back to an older revision', function () {
    beforeEach(function () {
        $this->draft = Settlement::calculate(Settle::run(($this->annex)()), 'ana', new DateTimeImmutable('2026-08-03 09:00'));
        $this->settlements->save($this->draft);
    });

    it('lets a recalculation, an approval and a closing replace the open version', function () {
        $recalculated = $this->draft->recalculate($this->draft->result, 'ana', new DateTimeImmutable('2026-08-04 09:00'));
        $approved = $recalculated->approve('beto', new DateTimeImmutable('2026-08-04 10:00'), 2);
        $closed = $approved->close('beto', new DateTimeImmutable('2026-08-04 11:00'));

        foreach ([$recalculated, $approved, $closed] as $settlement) {
            $this->settlements->save($settlement);

            expect($this->settlements->latest($this->july))->toBe($settlement);
        }
    });

    it('refuses an approval of an older revision saved after a recalculation', function () {
        // Both users loaded revision 1; the analyst's recalculation is saved first.
        $approvedLate = $this->draft->approve('beto', new DateTimeImmutable('2026-08-04 10:00'), 1);
        $recalculated = $this->draft->recalculate($this->draft->result, 'ana', new DateTimeImmutable('2026-08-04 09:00'));
        $this->settlements->save($recalculated);

        try {
            $this->settlements->save($approvedLate);
            $this->fail('The stale approval should have been refused.');
        } catch (StaleSettlement $exception) {
            expect($exception->expectedRevision())->toBe(1)
                ->and($exception->currentRevision())->toBe(2)
                ->and($this->settlements->latest($this->july))->toBe($recalculated);
        }
    });
});

describe('frozen parameters (§4, §6 step 6)', function () {
    it('keeps the stored result of a closed version when the parameters change afterwards', function () {
        $this->inputs->put(($this->annex)());
        $this->calculate->handle($this->july, 'ana', new DateTimeImmutable('2026-08-03 09:00'));
        $this->approve->handle($this->july, 'beto', new DateTimeImmutable('2026-08-04 09:00'), 1);
        $closed = $this->close->handle($this->july, 'beto', new DateTimeImmutable('2026-08-04 10:00'));

        // The administrator raises code 14 to 1 % for the whole year, July included.
        $book = DefaultParameters::book();
        $this->parameters->change(new ParameterBook(
            codes: [...array_values(array_filter($book->codes, fn (SellerCodeRule $rule) => $rule->code !== 14)), Params::rule(14, saleRate: '1')],
            trancheTables: $book->trancheTables,
            teams: $book->teams,
            derived: $book->derived,
        ));

        $stored = $this->settlements->versions($this->july)[0];

        expect($stored)->toBe($closed)
            ->and(($this->saleCommissionOf14)($stored))->toBe('524364')
            ->and($stored->result->parameters()->code(14)?->saleRate->percent())->toBe('0.75');

        // Only a complementary version takes the new parameters, and freezes them in turn.
        $complementary = $this->complement->handle($this->july, 'carla', 'Tasa del código 14 corregida.', new DateTimeImmutable('2026-09-01 08:00'));

        expect(($this->saleCommissionOf14)($complementary))->toBe('699151')
            ->and($complementary->result->parameters()->code(14)?->saleRate->percent())->toBe('1')
            ->and(($this->saleCommissionOf14)($this->settlements->versions($this->july)[0]))->toBe('524364');
    });
});
