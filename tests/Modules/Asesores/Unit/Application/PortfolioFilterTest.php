<?php

use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;

it('starts from the biggest overdue amount, with no filters', function () {
    $filter = PortfolioFilter::fromQuery([]);
    $query = $filter->clientQuery();

    expect($query->sort)->toBe('overdue')
        ->and($query->direction)->toBe('desc')
        ->and($query->search)->toBeNull()
        ->and($query->statuses)->toBe([])
        ->and($query->advisorCode)->toBeNull()
        ->and($query->companies)->toBe([])
        ->and($query->onlyOverdue)->toBeFalse()
        ->and($filter->params())->toBe([]);
});

it('reads every filter from the query string', function () {
    $filter = PortfolioFilter::fromQuery([
        'asesor' => 'SDC-12',
        'buscar' => '  Ferretería  ',
        'estado' => 'vencida',
        'vencidos' => '1',
        'sort' => 'balance',
        'direction' => 'asc',
    ]);
    $query = $filter->clientQuery();

    expect($query->advisorCode)->toBe(12)
        ->and($query->companies)->toBe([CompanyCode::SDC])
        ->and($query->search)->toBe('Ferretería')
        ->and($query->statuses)->toBe([ClientStatus::Overdue])
        ->and($query->onlyOverdue)->toBeTrue()
        ->and($query->sort)->toBe('balance')
        ->and($query->direction)->toBe('asc');
});

it('ignores values it does not know instead of failing', function () {
    $filter = PortfolioFilter::fromQuery([
        'asesor' => 'nadie',
        'estado' => 'no-existe',
        'sort' => 'password',
        'direction' => 'sideways',
        'buscar' => ['array'],
        'vencidos' => 'maybe',
    ]);
    $query = $filter->clientQuery();

    expect($query->advisorCode)->toBeNull()
        ->and($query->statuses)->toBe([])
        ->and($query->sort)->toBe('overdue')
        ->and($query->direction)->toBe('desc')
        ->and($query->search)->toBeNull()
        ->and($query->onlyOverdue)->toBeFalse();
});

it('cuts a very long search', function () {
    $query = PortfolioFilter::fromQuery(['buscar' => str_repeat('a', 500)])->clientQuery();

    expect(mb_strlen((string) $query->search))->toBe(100);
});

it('keeps only the advisor for the figures that ignore the other filters', function () {
    $filter = PortfolioFilter::fromQuery(['asesor' => 'SGX-30', 'buscar' => 'x', 'estado' => 'vencida', 'vencidos' => '1']);
    $query = $filter->advisorQuery();

    expect($query->advisorCode)->toBe(30)
        ->and($query->companies)->toBe([CompanyCode::SGX])
        ->and($query->search)->toBeNull()
        ->and($query->statuses)->toBe([])
        ->and($query->onlyOverdue)->toBeFalse();
});

it('writes only what differs from the defaults into links', function () {
    $filter = PortfolioFilter::fromQuery(['asesor' => 'SGX-12', 'buscar' => 'tornillo', 'sort' => 'name', 'direction' => 'asc']);

    expect($filter->params())->toBe(['asesor' => 'SGX-12', 'buscar' => 'tornillo', 'sort' => 'name', 'direction' => 'asc'])
        ->and($filter->params(['buscar' => null, 'asesor' => 'SDC-1']))->toBe(['asesor' => 'SDC-1', 'sort' => 'name', 'direction' => 'asc']);
});
