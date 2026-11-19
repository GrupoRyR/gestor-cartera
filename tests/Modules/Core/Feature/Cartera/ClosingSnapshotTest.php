<?php

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Tests\Modules\Core\Support\CarteraRecords;

/*
 * The closing snapshot of a month (docs/arquitectura.md §6.4): the robot
 * dates each load with the day it runs, so the photo of the end of October
 * is the load of 1/11 at 00:00, cut on 1/11 and received before 03:00 in
 * Bogotá. The 12:00 load of 31/10 misses the afternoon payments; the 05:00
 * and 12:00 loads of 1/11 already belong to November.
 */
beforeEach(function () {
    $this->load = fn (string $cutoff, string $receivedAt, CompanyCode $company = CompanyCode::SGX): PortfolioLoadRecord => PortfolioLoadRecord::factory()->create([
        'company' => $company,
        'cutoff_date' => $cutoff,
        'created_at' => CarteraRecords::at($receivedAt),
    ]);

    $this->noonOf31 = ($this->load)('2026-10-31', '2026-10-31 12:04:00');
    $this->closing = ($this->load)('2026-11-01', '2026-11-01 00:06:00');
    $this->morningOf1 = ($this->load)('2026-11-01', '2026-11-01 05:03:00');

    $this->agro = CarteraRecords::client('Agro Arango', ['status' => ClientStatus::Overdue, 'balance' => '900.00', 'overdue' => '0.00']);
    $this->banco = CarteraRecords::client('Banco Bolívar');

    foreach ([$this->noonOf31, $this->morningOf1] as $other) {
        CarteraRecords::invoice($other, $this->agro, '900.00');
    }

    $this->late = CarteraRecords::invoice($this->closing, $this->agro, '1500.00', daysOverdue: 12);
    $this->current = CarteraRecords::invoice($this->closing, $this->agro, '500.00', daysOverdue: -5);
    CarteraRecords::invoice($this->closing, $this->banco, '300.00', daysOverdue: 40);
});

function closingOf(int $month = 10, CompanyCode $company = CompanyCode::SGX): ?CarteraRepository
{
    return CarteraRecords::repository()->closingSnapshot($company, 2026, $month);
}

it('reads the open invoices of the load received before 03:00 on the first day of the next month', function () {
    $ids = array_map(fn (Invoice $invoice): int => $invoice->id, closingOf()->openInvoices($this->agro->id));

    expect($ids)->toBe([$this->late->id, $this->current->id]);
});

it('takes the earliest of the loads received before 03:00', function () {
    $second = ($this->load)('2026-11-01', '2026-11-01 02:40:00');
    CarteraRecords::invoice($second, $this->agro, '1.00');

    expect(closingOf()->openInvoices($this->agro->id))->toHaveCount(2);
});

it('has no closing snapshot without a load of the first day received before 03:00', function () {
    $this->closing->forceFill(['created_at' => CarteraRecords::at('2026-11-01 03:00:00')])->save();

    expect(closingOf())->toBeNull()
        ->and(closingOf(9))->toBeNull()
        ->and(closingOf(10, CompanyCode::SDC))->toBeNull();
});

it('finds the closing of December in January of the next year', function () {
    $january = ($this->load)('2027-01-01', '2027-01-01 00:07:00');
    CarteraRecords::invoice($january, $this->agro, '42.00');

    expect(closingOf(12)?->openInvoices($this->agro->id))->toHaveCount(1);
});

it('refuses a month out of range', function (int $month) {
    expect(fn () => closingOf($month))->toThrow(InvalidArgumentException::class);
})->with([0, 13]);

it('lists the clients of the company with the figures of the closing and their current data', function () {
    $clients = [...closingOf()->allClients(new ClientQuery(sort: 'balance', direction: 'desc'))];
    $agro = $clients[0];

    expect(CarteraRecords::namesOf($clients))->toBe(['Agro Arango', 'Banco Bolívar'])
        ->and($agro->balance)->toBe(2000.0)
        ->and($agro->overdue)->toBe(1500.0)
        ->and($agro->maxDaysOverdue)->toBe(12)
        ->and($agro->status)->toBe(ClientStatus::Overdue)
        ->and(closingOf()->client($this->agro->id)?->summary->balance)->toBe(2000.0);
});

it('filters on the figures of the closing', function () {
    $this->agro->update(['overdue' => '0.00']);
    $this->banco->update(['overdue' => '0.00']);

    expect(CarteraRecords::names(new ClientQuery(onlyOverdue: true, sort: 'overdue', direction: 'desc'), closingOf()))
        ->toBe(['Agro Arango', 'Banco Bolívar']);
});

it('adds up the closing at the last day of the month', function () {
    $totals = closingOf()->totals();

    expect($totals->total)->toBe(2300.0)
        ->and($totals->overdue)->toBe(1800.0)
        ->and($totals->clientCount)->toBe(2)
        ->and($totals->cutoffDate->toDateString())->toBe('2026-10-31');
});

it('ignores the signed-in user and sees only the company of the closing', function () {
    $sdc = ($this->load)('2026-11-01', '2026-11-01 00:09:00', CompanyCode::SDC);
    $granos = CarteraRecords::client('Granos Giraldo', ['company' => CompanyCode::SDC]);
    CarteraRecords::invoice($sdc, $granos, '700.00');

    $closing = closingOf();

    expect(CarteraRecords::names(repository: $closing))->toBe(['Agro Arango', 'Banco Bolívar'])
        ->and($closing->openInvoices($granos->id))->toBe([])
        ->and($closing->client($granos->id))->toBeNull()
        ->and(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SDC]), $closing))->toBe([])
        ->and($closing->asSystem())->toBe($closing)
        ->and(closingOf(10, CompanyCode::SDC)?->openInvoices($granos->id))->toHaveCount(1);
});
