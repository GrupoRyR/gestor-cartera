<?php

use App\Modules\Bancos\Database\Seeders\BancosDemoSeeder;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Bogota'));
});

it('creates fictitious credits for both companies and the four banks, in one load each', function () {
    $this->seed(BancosDemoSeeder::class);

    expect(BankLoad::query()->count())->toBe(8)
        ->and(BankMovement::query()->count())->toBeGreaterThan(20)
        ->and(BankMovement::query()->distinct()->pluck('company')->map->value->sort()->values()->all())->toBe(['SDC', 'SGX'])
        ->and(BankMovement::query()->distinct()->pluck('bank')->count())->toBe(4);
});

it('keeps the Miami account in dollars, the rest in pesos', function () {
    $this->seed(BancosDemoSeeder::class);

    expect(BankMovement::query()->where('bank', Bank::Miami->value)->distinct()->pluck('currency')->all())->toBe(['USD'])
        ->and(BankMovement::query()->where('bank', '!=', Bank::Miami->value)->distinct()->pluck('currency')->all())->toBe(['COP']);
});

it('gives a receipt only to the applied credits, and nothing dated today or on a Sunday', function () {
    $this->seed(BancosDemoSeeder::class);

    foreach (BankMovement::query()->get() as $movement) {
        expect($movement->receipt_number !== null)->toBe($movement->status === MovementStatus::Applied)
            ->and($movement->movement_date->lessThan(CarbonImmutable::parse('2026-10-16', 'America/Bogota')))->toBeTrue()
            ->and($movement->movement_date->isSunday())->toBeFalse();
    }
});

it('does nothing when there are movements already', function () {
    $this->seed(BancosDemoSeeder::class);
    $count = BankMovement::query()->count();

    $this->seed(BancosDemoSeeder::class);

    expect(BankMovement::query()->count())->toBe($count);
});
