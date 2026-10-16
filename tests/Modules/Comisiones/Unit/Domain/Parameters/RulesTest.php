<?php

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Parameters\DerivedCommissionRule;
use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;
use Tests\Modules\Comisiones\Support\Params;

describe('SellerCodeRule', function () {
    it('keys a code by its person', function () {
        $rule = Params::rule(32, person: new PersonRef('harold-marin', 'Harold Marín'));

        expect($rule->personKey())->toBe('harold-marin')
            ->and($rule->personRef()->name)->toBe('Harold Marín');
    });

    it('falls back to the code when it has no person', function () {
        $rule = Params::rule(17);

        expect($rule->personKey())->toBe('codigo-17')
            ->and($rule->personRef()->id)->toBe('codigo-17')
            ->and($rule->personRef()->name)->toBe('Código 17');
    });

    it('uses tranches unless told otherwise', function () {
        expect(Params::rule(14)->usesTranches)->toBeTrue();
    });

    it('rejects a negative code or a blank name', function (int $code, string $name) {
        new SellerCodeRule(
            Validity::from('2026-01-01'),
            $code,
            $name,
            null,
            Percentage::of('0.75'),
            Percentage::of('0.75'),
            Percentage::of(90),
            CodeScope::Regular,
        );
    })->throws(InvalidArgumentException::class)->with([
        'negative code' => [-1, 'Código'],
        'blank name' => [14, ' '],
    ]);
});

describe('CodeScope', function () {
    it('has a Spanish label', function (CodeScope $scope, string $label) {
        expect($scope->label())->toBe($label);
    })->with([
        'regular' => [CodeScope::Regular, 'Regular'],
        'separate' => [CodeScope::Separate, 'Aparte'],
        'control' => [CodeScope::Control, 'Control'],
    ]);
});

describe('DirectorTeam', function () {
    it('keeps its members in order', function () {
        expect(Params::team('distribucion', [14, 15, 16])->memberCodes)->toBe([14, 15, 16]);
    });

    it('rejects an empty or repeated member list', function (array $members) {
        Params::team('distribucion', $members);
    })->throws(InvalidArgumentException::class)->with([
        'empty' => [[]],
        'repeated' => [[14, 15, 14]],
    ]);

    it('rejects a blank key or channel', function (string $key, string $channel) {
        new DirectorTeam(
            Validity::from('2026-01-01'),
            $key,
            $channel,
            new PersonRef('jessika-quintero', 'Jessika Quintero'),
            53,
            Percentage::of('0.2'),
            [14],
        );
    })->throws(InvalidArgumentException::class)->with([
        'blank key' => ['', 'Distribución'],
        'blank channel' => ['distribucion', '  '],
    ]);
});

describe('DerivedCommissionRule', function () {
    it('keeps its source and base', function () {
        $rule = Params::derived('81.1');

        expect($rule->key)->toBe('81.1')
            ->and($rule->sourceCode)->toBe(81)
            ->and($rule->base)->toBe(DerivedBase::Sales)
            ->and($rule->rate->percent())->toBe('0.1');
    });

    it('rejects a blank key or label', function (string $key, string $label) {
        new DerivedCommissionRule(
            Validity::from('2026-01-01'),
            $key,
            $label,
            new PersonRef('sergio-caballero', 'Sergio Caballero'),
            81,
            DerivedBase::Sales,
            Percentage::of('0.1'),
        );
    })->throws(InvalidArgumentException::class)->with([
        'blank key' => [' ', 'Sergio Caballero (venta)'],
        'blank label' => ['81.1', ''],
    ]);

    it('labels its base in Spanish', function () {
        expect(DerivedBase::Sales->label())->toBe('Venta')
            ->and(DerivedBase::Collections->label())->toBe('Recaudo');
    });
});
