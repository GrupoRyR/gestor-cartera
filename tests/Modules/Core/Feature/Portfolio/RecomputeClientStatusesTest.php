<?php

use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Modules\Core\Support\RobotBatches;

it('recomputes the statuses again after an admin changes the grace days', function () {
    $load = RobotBatches::install($this->app)->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['fecha_vencimiento' => '2026-09-29']),
    ]);
    $client = ClientRecord::query()->sole();

    expect($client->status)->toBe(ClientStatus::Overdue);

    CompanyRecord::query()->whereKey('SGX')->update(['grace_days' => 20]);
    app(CompanyCatalog::class)->refresh();

    app(RecomputeClientStatuses::class)->forCompany(CompanyCode::SGX);

    $entry = AuditLogRecord::query()->where('action', 'core.client.status_changed')->latest('id')->firstOrFail();

    expect($client->refresh()->status)->toBe(ClientStatus::DueSoon)
        ->and($entry->changes)->toEqual(['status' => ['old' => 'vencida', 'new' => 'por_vencer']])
        ->and($entry->context)->toEqual(['load_id' => $load->id, 'cutoff_date' => '2026-10-15']);
});

it('recomputes from the snapshot it is given', function () {
    $client = ClientRecord::factory()->create(['company' => CompanyCode::SDC]);
    $older = PortfolioLoadRecord::factory()->create(['company' => CompanyCode::SDC, 'cutoff_date' => '2026-10-01']);
    PortfolioLoadRecord::factory()->create(['company' => CompanyCode::SDC, 'cutoff_date' => '2026-10-15']);
    InvoiceRecord::factory()->for($older, 'portfolioLoad')->for($client, 'client')->create([
        'company' => CompanyCode::SDC, 'balance' => '700.25', 'days_overdue' => 9,
    ]);

    app(RecomputeClientStatuses::class)->forCompany(CompanyCode::SDC, $older->id);

    $client->refresh();

    expect($client->status)->toBe(ClientStatus::Overdue)
        ->and($client->balance)->toBe('700.25')
        ->and($client->overdue)->toBe('700.25')
        ->and($client->max_days_overdue)->toBe(9);
});

it('refuses a snapshot of another company', function () {
    $load = PortfolioLoadRecord::factory()->create(['company' => CompanyCode::SGX]);

    expect(fn () => app(RecomputeClientStatuses::class)->forCompany(CompanyCode::SDC, $load->id))
        ->toThrow(ModelNotFoundException::class);
});

it('changes nothing while the company has no snapshot', function () {
    $client = ClientRecord::factory()->create([
        'status' => ClientStatus::Overdue, 'balance' => '10.00', 'overdue' => '10.00', 'max_days_overdue' => 30,
    ]);

    app(RecomputeClientStatuses::class)->forCompany(CompanyCode::SGX);

    $client->refresh();

    expect($client->status)->toBe(ClientStatus::Overdue)
        ->and($client->balance)->toBe('10.00')
        ->and(AuditLogRecord::query()->count())->toBe(0);
});
