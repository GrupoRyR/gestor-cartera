<?php

use App\Modules\Asesores\Application\Portfolio\DataFreshness;
use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\FakeIngestaSink;

beforeEach(function () {
    Event::fake([BatchAccepted::class]);
    $this->sink = new FakeIngestaSink;

    $this->receive = function (Dataset $dataset, CompanyCode $company, string $cutoff, string $receivedAt): void {
        $this->travelTo(CarbonImmutable::parse($receivedAt, 'UTC'));
        $this->sink->receive(new IncomingBatch($dataset, $company, CarbonImmutable::parse($cutoff, 'America/Bogota'), LoadSource::Robot, uniqid('lote-'), []));
    };
});

it('is null without a cartera load', function () {
    ($this->receive)(Dataset::Advisors, CompanyCode::SGX, '2026-11-10', '2026-11-11 12:10');

    expect((new DataFreshness($this->sink, new FakeCurrentCompany))->updatedAt())->toBeNull();
});

it('is null for guests, whatever was loaded', function () {
    ($this->receive)(Dataset::Receivables, CompanyCode::SGX, '2026-11-10', '2026-11-11 12:10');

    expect((new DataFreshness($this->sink, FakeCurrentCompany::guest()))->updatedAt())->toBeNull();
});

it('gives the receipt of the latest cartera load of the active company, in Bogotá', function () {
    ($this->receive)(Dataset::Receivables, CompanyCode::SGX, '2026-11-09', '2026-11-10 12:00');
    ($this->receive)(Dataset::Receivables, CompanyCode::SGX, '2026-11-10', '2026-11-11 12:10');
    ($this->receive)(Dataset::Receivables, CompanyCode::SDC, '2026-11-08', '2026-11-11 13:00');

    $updatedAt = (new DataFreshness($this->sink, new FakeCurrentCompany(CompanyCode::SGX)))->updatedAt();

    expect($updatedAt?->format('Y-m-d H:i'))->toBe('2026-11-11 07:10')
        ->and($updatedAt?->getTimezone()->getName())->toBe('America/Bogota');
});

it('takes the company with the older cutoff in the group view', function () {
    ($this->receive)(Dataset::Receivables, CompanyCode::SGX, '2026-11-10', '2026-11-11 11:00');
    ($this->receive)(Dataset::Receivables, CompanyCode::SDC, '2026-11-09', '2026-11-11 13:00');

    expect((new DataFreshness($this->sink, FakeCurrentCompany::group()))->updatedAt()?->format('H:i'))->toBe('08:00');
});

it('takes the earlier receipt when both companies share the cutoff', function () {
    ($this->receive)(Dataset::Receivables, CompanyCode::SDC, '2026-11-10', '2026-11-11 13:00');
    ($this->receive)(Dataset::Receivables, CompanyCode::SGX, '2026-11-10', '2026-11-11 11:00');

    expect((new DataFreshness($this->sink, FakeCurrentCompany::group()))->updatedAt()?->format('H:i'))->toBe('06:00');
});

it('ignores a company of the group view that has no load', function () {
    ($this->receive)(Dataset::Receivables, CompanyCode::SDC, '2026-11-10', '2026-11-11 13:00');

    expect((new DataFreshness($this->sink, FakeCurrentCompany::group()))->updatedAt()?->format('H:i'))->toBe('08:00');
});
