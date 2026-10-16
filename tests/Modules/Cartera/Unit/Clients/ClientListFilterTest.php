<?php

use App\Modules\Cartera\Application\Clients\ClientListFilter;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use Carbon\CarbonImmutable;

it('lists the largest overdue amounts first when nothing is asked', function () {
    $filter = ClientListFilter::fromInput([]);
    $query = $filter->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

    expect($query->sort)->toBe('overdue')
        ->and($query->direction)->toBe('desc')
        ->and($query->search)->toBeNull()
        ->and($query->statuses)->toBe([])
        ->and($query->onlyOverdue)->toBeFalse()
        ->and($query->inactiveSince)->toBeNull()
        ->and($filter->isFiltered())->toBeFalse();
});

it('reads the search, state, advisor and overdue switches', function () {
    $filter = ClientListFilter::fromInput([
        'buscar' => '  tornillo  ',
        'estado' => 'vencida',
        'asesor' => '42',
        'vencidos' => '1',
        'sort' => 'name',
        'direction' => 'asc',
    ]);
    $query = $filter->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

    expect($query->search)->toBe('tornillo')
        ->and($query->statuses)->toBe([ClientStatus::Overdue])
        ->and($query->advisorCode)->toBe(42)
        ->and($query->onlyOverdue)->toBeTrue()
        ->and($query->sort)->toBe('name')
        ->and($query->direction)->toBe('asc')
        ->and($filter->isFiltered())->toBeTrue();
});

it('turns "sin gestión" into overdue clients with no action since the cutoff', function () {
    $query = ClientListFilter::fromInput(['sin_gestion' => '1'])->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

    expect($query->onlyOverdue)->toBeTrue()
        ->and($query->inactiveSince?->toDateString())->toBe('2026-10-09');
});

it('ignores values it does not know instead of failing', function (array $input) {
    $query = ClientListFilter::fromInput($input)->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

    expect($query->sort)->toBe('overdue')
        ->and($query->direction)->toBe('desc')
        ->and($query->statuses)->toBe([])
        ->and($query->advisorCode)->toBeNull()
        ->and($query->search)->toBeNull();
})->with([
    'unknown sort and direction' => [['sort' => 'password', 'direction' => 'sideways']],
    'unknown state' => [['estado' => 'archivado']],
    'advisor that is not a number' => [['asesor' => 'abc']],
    'search that is not text' => [['buscar' => ['x']]],
    'blank search' => [['buscar' => '   ']],
]);

it('keeps only what differs from the defaults in the links', function () {
    $filter = ClientListFilter::fromInput(['buscar' => 'ferr', 'estado' => 'vencida', 'sort' => 'overdue', 'direction' => 'desc']);

    expect($filter->query())->toBe(['buscar' => 'ferr', 'estado' => 'vencida']);
});
