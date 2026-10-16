<?php

use App\Modules\Comisiones\Domain\Parameters\CollectionTranche;
use App\Modules\Comisiones\Domain\Parameters\TrancheTable;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\Validity;
use Tests\Modules\Comisiones\Support\Params;

describe('CollectionTranche', function () {
    it('covers its days, both ends included', function (int $days, bool $expected) {
        expect((new CollectionTranche(66, 80, Percentage::of(90)))->covers($days))->toBe($expected);
    })->with([
        'first day' => [66, true],
        'last day' => [80, true],
        'day before' => [65, false],
        'day after' => [81, false],
    ]);

    it('has no end when open-ended', function () {
        expect((new CollectionTranche(126, null, Percentage::zero()))->covers(10_000))->toBeTrue();
    });

    it('rejects invalid tranches', function (int $from, ?int $to, string $share) {
        new CollectionTranche($from, $to, Percentage::of($share));
    })->throws(InvalidArgumentException::class)->with([
        'negative start' => [-1, 65, '100'],
        'end before start' => [80, 66, '90'],
        'share above 100 %' => [0, 65, '100.01'],
    ]);
});

describe('shareFor', function () {
    it('applies the §5.3 tranches', function (int $days, string $share) {
        $table = new TrancheTable(Validity::from('2026-01-01'), Params::standardTranches());

        expect($table->shareFor($days)->percent())->toBe($share);
    })->with([
        '0 days' => [0, '100'],
        '65 days' => [65, '100'],
        '66 days' => [66, '90'],
        '80 days' => [80, '90'],
        '81 days' => [81, '80'],
        '95 days' => [95, '80'],
        '96 days' => [96, '50'],
        '125 days' => [125, '50'],
        '126 days' => [126, '0'],
        '400 days' => [400, '0'],
    ]);

    it('rejects negative days', function () {
        (new TrancheTable(Validity::from('2026-01-01'), Params::standardTranches()))->shareFor(-1);
    })->throws(InvalidArgumentException::class);

    it('sorts the tranches by their first day', function () {
        $table = new TrancheTable(Validity::from('2026-01-01'), array_reverse(Params::standardTranches()));

        expect(array_map(fn (CollectionTranche $tranche): int => $tranche->fromDays, $table->tranches))
            ->toBe([0, 66, 81, 96, 126])
            ->and($table->shareFor(70)->percent())->toBe('90');
    });
});

describe('validation', function () {
    it('rejects tables that do not cover every day exactly once', function (Closure $tranches, string $message) {
        expect(fn () => new TrancheTable(Validity::from('2026-01-01'), $tranches()))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'empty' => [fn () => [], 'at least one tranche'],
        'not starting at 0' => [fn () => [
            new CollectionTranche(1, 65, Percentage::of(100)),
            new CollectionTranche(66, null, Percentage::zero()),
        ], 'start at 0 days'],
        'gap' => [fn () => [
            new CollectionTranche(0, 65, Percentage::of(100)),
            new CollectionTranche(70, null, Percentage::zero()),
        ], 'gap'],
        'overlap' => [fn () => [
            new CollectionTranche(0, 65, Percentage::of(100)),
            new CollectionTranche(60, null, Percentage::zero()),
        ], 'overlap'],
        'open-ended tranche before the last' => [fn () => [
            new CollectionTranche(0, null, Percentage::of(100)),
            new CollectionTranche(66, null, Percentage::zero()),
        ], 'overlap'],
        'closed last tranche' => [fn () => [
            new CollectionTranche(0, 65, Percentage::of(100)),
            new CollectionTranche(66, 125, Percentage::of(50)),
        ], 'open-ended'],
    ]);
});
