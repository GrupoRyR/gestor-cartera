<?php

use App\Modules\Cartera\Application\Clients\ClientListFilter;
use App\Modules\Cartera\Application\Clients\ManagerFilter;
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

describe('the manager filter', function () {
    it('shows the whole portfolio when nothing is asked and the default is the whole portfolio', function () {
        $filter = ClientListFilter::fromInput([], ManagerFilter::All, 7);
        $query = $filter->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

        expect($filter->manager)->toBe(ManagerFilter::All)
            ->and($query->managerUserId)->toBeNull()
            ->and($query->unassigned)->toBeFalse()
            ->and($filter->isFiltered())->toBeFalse()
            ->and($filter->query())->toBe([]);
    });

    it('turns "mios" into the clients of the user', function () {
        $filter = ClientListFilter::fromInput(['gestor' => 'mios'], ManagerFilter::All, 7);
        $query = $filter->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

        expect($filter->manager)->toBe(ManagerFilter::Mine)
            ->and($query->managerUserId)->toBe(7)
            ->and($query->unassigned)->toBeFalse()
            ->and($filter->isFiltered())->toBeTrue()
            ->and($filter->query())->toBe(['gestor' => 'mios']);
    });

    it('turns "sin_asignar" into the clients without a manager', function () {
        $filter = ClientListFilter::fromInput(['gestor' => 'sin_asignar'], ManagerFilter::All, 7);
        $query = $filter->toQuery(CarbonImmutable::parse('2026-10-16'), 7);

        expect($query->unassigned)->toBeTrue()
            ->and($query->managerUserId)->toBeNull()
            ->and($filter->query())->toBe(['gestor' => 'sin_asignar']);
    });

    it('starts on the default the caller gives, and an explicit "todos" overrides it', function () {
        $default = ClientListFilter::fromInput([], ManagerFilter::Mine, 7);
        $everything = ClientListFilter::fromInput(['gestor' => 'todos'], ManagerFilter::Mine, 7);

        expect($default->manager)->toBe(ManagerFilter::Mine)
            ->and($default->toQuery(CarbonImmutable::parse('2026-10-16'), 7)->managerUserId)->toBe(7)
            ->and($everything->manager)->toBe(ManagerFilter::All)
            ->and($everything->toQuery(CarbonImmutable::parse('2026-10-16'), 7)->managerUserId)->toBeNull()
            ->and($everything->isFiltered())->toBeFalse();
    });

    it('names the manager filter in the links whenever the default is not the whole portfolio', function () {
        expect(ClientListFilter::fromInput([], ManagerFilter::Mine, 7)->query())->toBe(['gestor' => 'mios'])
            ->and(ClientListFilter::fromInput(['gestor' => 'todos'], ManagerFilter::Mine, 7)->query())->toBe(['gestor' => 'todos']);
    });

    it('clears the filters to the whole portfolio, saying so when the default is another', function () {
        expect(ClientListFilter::fromInput(['gestor' => 'mios'], ManagerFilter::All, 7)->clearQuery())->toBe([])
            ->and(ClientListFilter::fromInput([], ManagerFilter::Mine, 7)->clearQuery())->toBe(['gestor' => 'todos']);
    });

    it('falls back to the default with a value it does not know', function (mixed $value) {
        expect(ClientListFilter::fromInput(['gestor' => $value], ManagerFilter::Mine, 7)->manager)->toBe(ManagerFilter::Mine);
    })->with(['otro', ['mios'], '']);

    it('filters nothing for "mios" without a user', function () {
        expect(ClientListFilter::fromInput(['gestor' => 'mios'])->toQuery(CarbonImmutable::parse('2026-10-16'), 7)->managerUserId)->toBeNull();
    });
});
