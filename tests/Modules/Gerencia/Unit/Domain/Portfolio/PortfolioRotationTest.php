<?php

use App\Modules\Gerencia\Domain\Portfolio\PortfolioRotation;

it('divides the collections of the period by the average portfolio', function () {
    $rotation = PortfolioRotation::from(collected: 300.0, averagePortfolio: 1_200.0, periodDays: 30);

    expect($rotation->times())->toBe(0.25)
        ->and($rotation->daysPerTurn())->toBe(120.0)
        ->and($rotation->collected)->toBe(300.0)
        ->and($rotation->averagePortfolio)->toBe(1_200.0)
        ->and($rotation->periodDays)->toBe(30);
});

it('turns the portfolio over once when it collects the whole average', function () {
    $rotation = PortfolioRotation::from(500.0, 500.0, 31);

    expect($rotation->times())->toBe(1.0)
        ->and($rotation->daysPerTurn())->toBe(31.0);
});

it('has no turns when the average portfolio is zero', function () {
    $rotation = PortfolioRotation::from(500.0, 0.0, 30);

    expect($rotation->times())->toBe(0.0)
        ->and($rotation->daysPerTurn())->toBeNull();
});

it('has no days per turn when nothing was collected', function () {
    $rotation = PortfolioRotation::from(0.0, 1_000.0, 30);

    expect($rotation->times())->toBe(0.0)
        ->and($rotation->daysPerTurn())->toBeNull();
});
