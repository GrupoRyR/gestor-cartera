<?php

use App\Modules\Cartera\Application\Commitments\CommitmentInboxFilter;
use App\Modules\Cartera\Application\Commitments\CommitmentTab;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;

it('shows the pending commitments, the soonest first, when nothing is asked', function () {
    $filter = CommitmentInboxFilter::fromInput([]);
    $query = $filter->toQuery();

    expect($filter->tab)->toBe(CommitmentTab::Pending)
        ->and($query->statuses)->toBe([CommitmentStatus::Pending])
        ->and($query->sort)->toBe('due_date')
        ->and($query->direction)->toBe('asc')
        ->and($query->advisorCode)->toBeNull()
        ->and($query->dueFrom)->toBeNull()
        ->and($query->dueTo)->toBeNull()
        ->and($filter->isFiltered())->toBeFalse();
});

it('maps each tab to the statuses it covers', function (string $tab, array $statuses) {
    expect(CommitmentInboxFilter::fromInput(['estado' => $tab])->toQuery()->statuses)->toBe($statuses);
})->with([
    'pending' => ['pendientes', [CommitmentStatus::Pending]],
    'broken' => ['incumplidos', [CommitmentStatus::Broken]],
    'fulfilled' => ['cumplidos', [CommitmentStatus::Fulfilled]],
    'cancelled' => ['anulados', [CommitmentStatus::Cancelled]],
    'all' => ['todos', []],
]);

it('reads the advisor, the due dates and the order', function () {
    $filter = CommitmentInboxFilter::fromInput([
        'estado' => 'todos',
        'asesor' => '42',
        'desde' => '2026-10-01',
        'hasta' => '2026-10-31',
        'sort' => 'amount',
        'direction' => 'desc',
    ]);
    $query = $filter->toQuery();

    expect($query->advisorCode)->toBe(42)
        ->and($query->dueFrom?->toDateString())->toBe('2026-10-01')
        ->and($query->dueTo?->toDateString())->toBe('2026-10-31')
        ->and($query->sort)->toBe('amount')
        ->and($query->direction)->toBe('desc')
        ->and($filter->isFiltered())->toBeTrue();
});

it('ignores values it does not know instead of failing', function (array $input) {
    $query = CommitmentInboxFilter::fromInput($input)->toQuery();

    expect($query->statuses)->toBe([CommitmentStatus::Pending])
        ->and($query->advisorCode)->toBeNull()
        ->and($query->dueFrom)->toBeNull()
        ->and($query->dueTo)->toBeNull()
        ->and($query->sort)->toBe('due_date')
        ->and($query->direction)->toBe('asc');
})->with([
    'unknown tab' => [['estado' => 'archivados']],
    'advisor that is not a number' => [['asesor' => 'abc']],
    'dates that are not dates' => [['desde' => 'ayer', 'hasta' => '2026-02-31']],
    'dates that are not text' => [['desde' => ['2026-10-01'], 'hasta' => ['2026-10-02']]],
    'unknown sort and direction' => [['sort' => 'password', 'direction' => 'sideways']],
]);

it('keeps the filters but not the tab in the links of the tabs', function () {
    $filter = CommitmentInboxFilter::fromInput(['estado' => 'incumplidos', 'asesor' => '42', 'desde' => '2026-10-01']);

    expect($filter->query(CommitmentTab::Fulfilled))->toBe(['estado' => 'cumplidos', 'asesor' => '42', 'desde' => '2026-10-01'])
        ->and($filter->query(CommitmentTab::Pending))->toBe(['asesor' => '42', 'desde' => '2026-10-01'])
        ->and($filter->query())->toBe(['estado' => 'incumplidos', 'asesor' => '42', 'desde' => '2026-10-01']);
});
