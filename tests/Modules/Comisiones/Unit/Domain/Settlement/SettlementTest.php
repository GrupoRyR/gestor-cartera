<?php

use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Settlement\InvalidTransition;
use App\Modules\Comisiones\Domain\Settlement\SegregationOfDuties;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementLocked;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Settlement\UnresolvedAlerts;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

/*
| The settlement lifecycle of §6 steps 4–7: a draft recalculated while the
| period is open, approved by someone else with every blocking alert
| justified (§9.7, §10), closed, and corrected only through complementary
| versions.
*/

beforeEach(function () {
    // Code 14 of Annex 2 (July 2026): no alerts at all.
    $this->clean = Settle::sales([Lines::sale(14, 69_915_134)], [Lines::budget(14, 77_241_857)]);
    $this->corrected = Settle::sales([Lines::sale(14, 72_000_000)], [Lines::budget(14, 77_241_857)]);

    // An unknown code 99 and code 15 with sales but no budget: two blocking alerts.
    $this->blocked = Settle::sales([Lines::sale(99, 1_000_000), Lines::sale(15, 1_000_000)]);

    // An annulled payment: a warning, which never stops the approval.
    $this->warned = Settle::run(new SettlementInput(
        Period::of(2026, 7),
        payments: [Lines::payment(14, 1_000_000, paymentDate: '2026-07-10', days: 10, key: 'P-A', annulled: true)],
    ));

    $this->monday = new DateTimeImmutable('2026-08-03 09:00:00');
    $this->tuesday = new DateTimeImmutable('2026-08-04 10:30:00');
    $this->friday = new DateTimeImmutable('2026-08-07 17:00:00');
});

describe('SettlementStatus', function () {
    it('has a Spanish label', function (SettlementStatus $status, string $value, string $label) {
        expect($status->value)->toBe($value)
            ->and($status->label())->toBe($label);
    })->with([
        'draft' => [SettlementStatus::Draft, 'borrador', 'Borrador'],
        'approved' => [SettlementStatus::Approved, 'aprobada', 'Aprobada'],
        'closed' => [SettlementStatus::Closed, 'cerrada', 'Cerrada'],
    ]);
});

describe('calculate (§6 step 4)', function () {
    it('starts version 1 of the period as an open draft', function () {
        $settlement = Settlement::calculate($this->clean, 'ana', $this->monday);

        expect($settlement->period->key())->toBe('2026-07')
            ->and($settlement->version)->toBe(1)
            ->and($settlement->status)->toBe(SettlementStatus::Draft)
            ->and($settlement->result)->toBe($this->clean)
            ->and($settlement->calculatedBy)->toBe('ana')
            ->and($settlement->calculatedAt)->toBe($this->monday)
            ->and($settlement->approvedBy)->toBeNull()
            ->and($settlement->approvedAt)->toBeNull()
            ->and($settlement->closedBy)->toBeNull()
            ->and($settlement->closedAt)->toBeNull()
            ->and($settlement->reason)->toBeNull()
            ->and($settlement->previousVersion)->toBeNull()
            ->and($settlement->justifications)->toBe([])
            ->and($settlement->isOpen())->toBeTrue();
    });

    it('needs the analyst who calculated it', function () {
        Settlement::calculate($this->clean, ' ', $this->monday);
    })->throws(InvalidArgumentException::class, 'calculated');
});

describe('the monthly flow (§6 steps 4–6)', function () {
    it('goes from draft to approved to closed, keeping the version and the result', function () {
        $draft = Settlement::calculate($this->clean, 'ana', $this->monday);
        $approved = $draft->approve('beto', $this->tuesday);
        $closed = $approved->close('beto', $this->friday);

        expect($approved->status)->toBe(SettlementStatus::Approved)
            ->and($approved->approvedBy)->toBe('beto')
            ->and($approved->approvedAt)->toBe($this->tuesday)
            ->and($approved->isOpen())->toBeTrue()
            ->and($closed->status)->toBe(SettlementStatus::Closed)
            ->and($closed->closedBy)->toBe('beto')
            ->and($closed->closedAt)->toBe($this->friday)
            ->and($closed->approvedBy)->toBe('beto')
            ->and($closed->isOpen())->toBeFalse()
            ->and($closed->version)->toBe(1)
            ->and($closed->result)->toBe($this->clean)
            ->and($closed->calculatedBy)->toBe('ana');
    });

    it('never changes the instance a transition starts from', function () {
        $draft = Settlement::calculate($this->clean, 'ana', $this->monday);
        $draft->approve('beto', $this->tuesday);

        expect($draft->status)->toBe(SettlementStatus::Draft)
            ->and($draft->approvedBy)->toBeNull();
    });
});

describe('recalculate (§6 step 4)', function () {
    it('replaces the result of a draft, in the same version', function () {
        $draft = Settlement::calculate($this->clean, 'ana', $this->monday)
            ->recalculate($this->corrected, 'carla', $this->tuesday);

        expect($draft->status)->toBe(SettlementStatus::Draft)
            ->and($draft->version)->toBe(1)
            ->and($draft->result)->toBe($this->corrected)
            ->and($draft->calculatedBy)->toBe('carla')
            ->and($draft->calculatedAt)->toBe($this->tuesday);
    });

    it('takes an approved settlement back to draft, clearing the approval and the justifications', function () {
        $approved = Settlement::calculate($this->blocked, 'ana', $this->monday)->approve('beto', $this->tuesday, [
            'codigo_desconocido:99' => 'Código nuevo, se crea en julio.',
            'sin_presupuesto:15' => 'Presupuesto pendiente de comercial.',
        ]);
        $draft = $approved->recalculate($this->corrected, 'ana', $this->friday);

        expect($draft->status)->toBe(SettlementStatus::Draft)
            ->and($draft->version)->toBe(1)
            ->and($draft->result)->toBe($this->corrected)
            ->and($draft->approvedBy)->toBeNull()
            ->and($draft->approvedAt)->toBeNull()
            ->and($draft->justifications)->toBe([])
            ->and($draft->isOpen())->toBeTrue();
    });

    it('refuses to recalculate a closed period', function () {
        $closed = Settlement::calculate($this->clean, 'ana', $this->monday)
            ->approve('beto', $this->tuesday)
            ->close('beto', $this->friday);

        expect(fn () => $closed->recalculate($this->corrected, 'ana', $this->friday))
            ->toThrow(SettlementLocked::class, 'Version 1 of the 2026-07 settlement is closed');
    });

    it('refuses a result of another period', function () {
        $august = Settle::collections('2026-08');

        expect(fn () => Settlement::calculate($this->clean, 'ana', $this->monday)->recalculate($august, 'ana', $this->tuesday))
            ->toThrow(InvalidArgumentException::class, '2026-08');
    });
});

describe('approve (§6 step 5, §9.7, §10)', function () {
    it('refuses an approval by the analyst who calculated it', function (string $approver) {
        $draft = Settlement::calculate($this->clean, 'ana@segurex.co', $this->monday);

        expect(fn () => $draft->approve($approver, $this->tuesday))->toThrow(SegregationOfDuties::class);
    })->with([
        'the same user' => ['ana@segurex.co'],
        'the same user in other case and spacing' => [' Ana@Segurex.co '],
    ]);

    it('refuses an approval while a blocking alert has no justification', function () {
        $draft = Settlement::calculate($this->blocked, 'ana', $this->monday);

        try {
            $draft->approve('beto', $this->tuesday, ['sin_presupuesto:15' => '   ']);
            $this->fail('The approval should have been refused.');
        } catch (UnresolvedAlerts $exception) {
            expect($exception->keys())->toBe(['codigo_desconocido:99', 'sin_presupuesto:15'])
                ->and($exception->getMessage())->toContain('codigo_desconocido:99, sin_presupuesto:15');
        }
    });

    it('approves once every blocking alert is justified, keeping the justifications in alert order', function () {
        $approved = Settlement::calculate($this->blocked, 'ana', $this->monday)->approve('beto', $this->tuesday, [
            'sin_presupuesto:15' => '  Presupuesto pendiente de comercial. ',
            'codigo_desconocido:99' => 'Código nuevo, se crea en julio.',
        ]);

        expect($approved->status)->toBe(SettlementStatus::Approved)
            ->and($approved->justifications)->toBe([
                'codigo_desconocido:99' => 'Código nuevo, se crea en julio.',
                'sin_presupuesto:15' => 'Presupuesto pendiente de comercial.',
            ]);
    });

    it('does not ask to justify warnings', function () {
        expect($this->warned->alerts())->toHaveCount(1)
            ->and(Settlement::calculate($this->warned, 'ana', $this->monday)->approve('beto', $this->tuesday)->status)
            ->toBe(SettlementStatus::Approved);
    });

    it('rejects a justification for anything but a blocking alert of the result', function (string $key) {
        $draft = Settlement::calculate($this->warned, 'ana', $this->monday);

        expect(fn () => $draft->approve('beto', $this->tuesday, [$key => 'Revisado.']))
            ->toThrow(InvalidArgumentException::class, $key);
    })->with([
        'a warning' => ['recaudo_anulado:pago:P-A'],
        'an alert the result does not have' => ['codigo_desconocido:99'],
    ]);

    it('approves only a draft', function (Closure $settle) {
        expect(fn () => $settle($this)->approve('dora', $this->friday))
            ->toThrow(InvalidTransition::class, 'Cannot approve version 1 of the 2026-07 settlement');
    })->with([
        'approved' => [fn ($test) => Settlement::calculate($test->clean, 'ana', $test->monday)->approve('beto', $test->tuesday)],
        'closed' => [fn ($test) => Settlement::calculate($test->clean, 'ana', $test->monday)->approve('beto', $test->tuesday)->close('beto', $test->friday)],
    ]);

    it('needs the approver', function () {
        Settlement::calculate($this->clean, 'ana', $this->monday)->approve('', $this->tuesday);
    })->throws(InvalidArgumentException::class, 'approved');
});

describe('close (§6 step 6)', function () {
    it('refuses to close before the approval', function () {
        expect(fn () => Settlement::calculate($this->clean, 'ana', $this->monday)->close('beto', $this->friday))
            ->toThrow(InvalidTransition::class, 'Cannot close version 1 of the 2026-07 settlement while it is draft');
    });

    it('refuses to close twice', function () {
        $closed = Settlement::calculate($this->clean, 'ana', $this->monday)->approve('beto', $this->tuesday)->close('beto', $this->friday);

        expect(fn () => $closed->close('beto', $this->friday))->toThrow(InvalidTransition::class, 'while it is closed');
    });

    it('needs the user who closes it', function () {
        Settlement::calculate($this->clean, 'ana', $this->monday)->approve('beto', $this->tuesday)->close(' ', $this->friday);
    })->throws(InvalidArgumentException::class, 'closed');
});

describe('complement (§6 step 7)', function () {
    beforeEach(function () {
        $this->closed = Settlement::calculate($this->clean, 'ana', $this->monday)
            ->approve('beto', $this->tuesday)
            ->close('beto', $this->friday);
    });

    it('opens a new draft version over a closed settlement, which stays as it was', function () {
        $later = new DateTimeImmutable('2026-09-02 08:00:00');
        $complementary = $this->closed->complement('  Venta de julio reclasificada al código 14. ', $this->corrected, 'carla', $later);

        expect($complementary->status)->toBe(SettlementStatus::Draft)
            ->and($complementary->version)->toBe(2)
            ->and($complementary->previousVersion)->toBe(1)
            ->and($complementary->reason)->toBe('Venta de julio reclasificada al código 14.')
            ->and($complementary->result)->toBe($this->corrected)
            ->and($complementary->calculatedBy)->toBe('carla')
            ->and($complementary->calculatedAt)->toBe($later)
            ->and($complementary->approvedBy)->toBeNull()
            ->and($complementary->closedBy)->toBeNull()
            ->and($complementary->isOpen())->toBeTrue()
            ->and($this->closed->status)->toBe(SettlementStatus::Closed)
            ->and($this->closed->version)->toBe(1)
            ->and($this->closed->result)->toBe($this->clean)
            ->and($this->closed->reason)->toBeNull();
    });

    it('chains complementary versions, each one over the closed one before it', function () {
        $third = $this->closed
            ->complement('Primera corrección.', $this->corrected, 'carla', $this->friday)
            ->approve('beto', $this->friday)
            ->close('beto', $this->friday)
            ->complement('Segunda corrección.', $this->clean, 'carla', $this->friday);

        expect($third->version)->toBe(3)
            ->and($third->previousVersion)->toBe(2)
            ->and($third->reason)->toBe('Segunda corrección.');
    });

    it('keeps the reason when a complementary draft is recalculated', function () {
        $draft = $this->closed->complement('Corrección.', $this->corrected, 'carla', $this->friday)
            ->recalculate($this->clean, 'carla', $this->friday);

        expect($draft->version)->toBe(2)
            ->and($draft->previousVersion)->toBe(1)
            ->and($draft->reason)->toBe('Corrección.');
    });

    it('refuses to complement an open settlement', function (Closure $settle, string $status) {
        expect(fn () => $settle($this)->complement('Corrección.', $this->corrected, 'carla', $this->friday))
            ->toThrow(InvalidTransition::class, "Cannot complement version 1 of the 2026-07 settlement while it is {$status}");
    })->with([
        'draft' => [fn ($test) => Settlement::calculate($test->clean, 'ana', $test->monday), 'draft'],
        'approved' => [fn ($test) => Settlement::calculate($test->clean, 'ana', $test->monday)->approve('beto', $test->tuesday), 'approved'],
    ]);

    it('refuses a blank reason', function () {
        $this->closed->complement(" \n", $this->corrected, 'carla', $this->friday);
    })->throws(InvalidArgumentException::class, 'reason');

    it('refuses a result of another period', function () {
        $this->closed->complement('Corrección.', Settle::collections('2026-08'), 'carla', $this->friday);
    })->throws(InvalidArgumentException::class, '2026-08');
});

describe('rebuilding a stored settlement', function () {
    it('rebuilds a closed complementary version as it was stored', function () {
        $settlement = new Settlement(
            period: Period::of(2026, 7),
            version: 2,
            status: SettlementStatus::Closed,
            result: $this->blocked,
            calculatedBy: 'carla',
            calculatedAt: $this->monday,
            approvedBy: 'beto',
            approvedAt: $this->tuesday,
            closedBy: 'beto',
            closedAt: $this->friday,
            reason: 'Corrección.',
            previousVersion: 1,
            justifications: ['codigo_desconocido:99' => 'Código nuevo.', 'sin_presupuesto:15' => 'Pendiente.'],
        );

        expect($settlement->isOpen())->toBeFalse()
            ->and($settlement->complement('Otra.', $this->clean, 'carla', $this->friday)->version)->toBe(3);
    });

    it('rejects a state the lifecycle cannot reach', function (array $state, string $message) {
        $build = fn () => new Settlement(...[
            'period' => Period::of(2026, 7),
            'version' => 1,
            'status' => SettlementStatus::Draft,
            'result' => $this->clean,
            'calculatedBy' => 'ana',
            'calculatedAt' => $this->monday,
            ...$state,
        ]);

        expect($build)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'version 0' => [['version' => 0], 'version'],
        'a result of another period' => [['period' => Period::of(2026, 8)], '2026-07'],
        'a complementary version without reason' => [['version' => 2, 'previousVersion' => 1], 'reason'],
        'a complementary version over the wrong one' => [['version' => 3, 'previousVersion' => 1, 'reason' => 'X'], 'previous'],
        'a first version with a previous one' => [['previousVersion' => 1, 'reason' => 'X'], 'previous'],
        'a draft with an approval' => [['approvedBy' => 'beto', 'approvedAt' => new DateTimeImmutable], 'draft'],
        'a draft with justifications' => [['justifications' => ['codigo_desconocido:99' => 'X']], 'draft'],
        'an approval without approver' => [['status' => SettlementStatus::Approved], 'approved'],
        'an approval with a closing' => [[
            'status' => SettlementStatus::Approved,
            'approvedBy' => 'beto',
            'approvedAt' => new DateTimeImmutable,
            'closedBy' => 'beto',
            'closedAt' => new DateTimeImmutable,
        ], 'closed'],
        'a closing without user' => [[
            'status' => SettlementStatus::Closed,
            'approvedBy' => 'beto',
            'approvedAt' => new DateTimeImmutable,
        ], 'closed'],
    ]);

    it('rejects a stored approval by the analyst who calculated it', function () {
        new Settlement(
            period: Period::of(2026, 7),
            version: 1,
            status: SettlementStatus::Approved,
            result: $this->clean,
            calculatedBy: 'ana',
            calculatedAt: $this->monday,
            approvedBy: 'ANA',
            approvedAt: $this->tuesday,
        );
    })->throws(SegregationOfDuties::class);
});
