<?php

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\FakeIngestaSink;

/**
 * @param  list<array<string, scalar|null>>  $rows
 */
function fakeBatch(
    string $batchId,
    Dataset $dataset = Dataset::Receivables,
    CompanyCode $company = CompanyCode::SGX,
    string $cutoffDate = '2026-10-14',
    array $rows = [],
    LoadSource $source = LoadSource::Robot,
    ?int $receivedBy = null,
): IncomingBatch {
    return new IncomingBatch($dataset, $company, CarbonImmutable::parse($cutoffDate), $source, $batchId, $rows, $receivedBy);
}

beforeEach(function () {
    Event::fake([BatchAccepted::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00'));

    $this->sink = new FakeIngestaSink;
    $this->cartera = [
        ['codigo_sn' => 'C1', 'saldo' => '1000000.50', 'dias' => 12],
        ['codigo_sn' => 'C1', 'saldo' => '250000.00', 'dias' => -3],
        ['codigo_sn' => 'C2', 'saldo' => '400000.00', 'dias' => 0],
        ['codigo_sn' => 'C3', 'saldo' => '50000.25', 'dias' => 91],
    ];
});

it('accepts a batch, sums the cartera and dispatches BatchAccepted', function () {
    $load = $this->sink->receive(fakeBatch('cartera-SGX-2026-10-14', rows: $this->cartera));

    expect($load->id)->toBe(1)
        ->and($load->status)->toBe(LoadStatus::Accepted)
        ->and($load->dataset)->toBe(Dataset::Receivables)
        ->and($load->rowCount)->toBe(4)
        ->and($load->total)->toBe(1_700_000.75)
        ->and($load->overdue)->toBe(1_050_000.75)
        ->and($load->receivedAt->toDateTimeString())->toBe('2026-10-15 07:00:00')
        ->and($load->receivedBy)->toBeNull()
        ->and($load->error)->toBeNull()
        ->and(iterator_to_array($this->sink->rows($load->id)))->toBe($this->cartera);

    Event::assertDispatchedTimes(BatchAccepted::class, 1);
    Event::assertDispatched(BatchAccepted::class, fn (BatchAccepted $event): bool => $event->load === $load);
});

it('leaves totals empty for other datasets and names the uploader of manual loads', function () {
    $load = $this->sink->receive(fakeBatch(
        'asesores-manual-1',
        dataset: Dataset::Advisors,
        rows: [['slp_code' => 11, 'nombre' => 'Ana Ríos', 'email' => null, 'activo' => 'Y', 'clientes' => 40]],
        source: LoadSource::Manual,
        receivedBy: 7,
    ));

    expect($load->total)->toBeNull()
        ->and($load->overdue)->toBeNull()
        ->and($load->source)->toBe(LoadSource::Manual)
        ->and($load->receivedBy)->toBeInstanceOf(UserRef::class)
        ->and($load->receivedBy?->id)->toBe(7);
});

it('returns the original load for a repeated batch without dispatching again', function () {
    $first = $this->sink->receive(fakeBatch('cartera-SGX-2026-10-14', rows: $this->cartera));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 13:00:00'));

    $again = $this->sink->receive(fakeBatch('cartera-SGX-2026-10-14', rows: []));

    expect($again)->toBe($first)
        ->and($this->sink->history())->toHaveCount(1)
        ->and($this->sink->received())->toHaveCount(2);
    Event::assertDispatchedTimes(BatchAccepted::class, 1);
});

it('records a rejected batch and validates the same batch id again later', function () {
    $this->sink->failNextWith(new InvalidBatch('Missing column saldo.', ['rows.0.saldo' => ['Required.']]));

    expect(fn () => $this->sink->receive(fakeBatch('cartera-SGX-2026-10-14', rows: $this->cartera)))
        ->toThrow(InvalidBatch::class, 'Missing column saldo.');

    $rejected = $this->sink->history()[0];

    expect($rejected->status)->toBe(LoadStatus::Rejected)
        ->and($rejected->error)->toBe('Missing column saldo.')
        ->and(iterator_to_array($this->sink->rows($rejected->id)))->toBe([])
        ->and($this->sink->latest(Dataset::Receivables, CompanyCode::SGX))->toBeNull();
    Event::assertNotDispatched(BatchAccepted::class);

    $accepted = $this->sink->receive(fakeBatch('cartera-SGX-2026-10-14', rows: $this->cartera));

    expect($accepted->status)->toBe(LoadStatus::Accepted);
});

it('rejects a batch without id', function () {
    expect(fn () => $this->sink->receive(fakeBatch(' ')))->toThrow(InvalidBatch::class);
});

it('reports unknown loads', function () {
    expect(fn () => $this->sink->rows(404))->toThrow(RecordNotFound::class, 'load');
});

it('finds the latest accepted snapshot by cutoff date', function () {
    $this->sink->receive(fakeBatch('b1', cutoffDate: '2026-10-14'));
    $newest = $this->sink->receive(fakeBatch('b2', cutoffDate: '2026-10-15'));
    $this->sink->receive(fakeBatch('b3', cutoffDate: '2026-10-13'));
    $this->sink->receive(fakeBatch('b4', company: CompanyCode::SDC, cutoffDate: '2026-10-16'));
    $this->sink->failNextWith(new InvalidBatch('Broken file.'));
    rescue(fn () => $this->sink->receive(fakeBatch('b5', cutoffDate: '2026-10-20')), report: false);

    expect($this->sink->latest(Dataset::Receivables, CompanyCode::SGX))->toBe($newest)
        ->and($this->sink->latest(Dataset::Advisors, CompanyCode::SGX))->toBeNull();
});

it('lists the history newest first with filters and a limit', function () {
    $this->sink->receive(fakeBatch('b1'));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:00:00'));
    $this->sink->receive(fakeBatch('b2', dataset: Dataset::Advisors));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));
    $this->sink->receive(fakeBatch('b3', company: CompanyCode::SDC));

    $ids = fn (array $loads): array => array_map(fn (LoadRecord $load): int => $load->id, $loads);

    expect($ids($this->sink->history()))->toBe([3, 2, 1])
        ->and($ids($this->sink->history(Dataset::Receivables)))->toBe([3, 1])
        ->and($ids($this->sink->history(company: CompanyCode::SGX)))->toBe([2, 1])
        ->and($ids($this->sink->history(limit: 1)))->toBe([3])
        ->and(fn () => $this->sink->history(limit: 0))->toThrow(InvalidArgumentException::class);
});

it('asserts received batches', function () {
    $this->sink->assertNothingReceived();
    expect(fn () => $this->sink->assertReceived(Dataset::Receivables))->toThrow(AssertionFailedError::class);

    $this->sink->receive(fakeBatch('b1', company: CompanyCode::SDC));

    $this->sink->assertReceived(Dataset::Receivables);
    $this->sink->assertReceived(Dataset::Receivables, CompanyCode::SDC);
    expect(fn () => $this->sink->assertReceived(Dataset::Receivables, CompanyCode::SGX))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->sink->assertReceived(Dataset::Sales))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->sink->assertNothingReceived())->toThrow(AssertionFailedError::class);
});
