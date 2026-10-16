<?php

use App\Modules\Core\Application\Companies\RecomputeCompanyStatuses;
use App\Modules\Core\Application\Portfolio\CompanyStatusesLock;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Ingest\ImportReceivablesSnapshot;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Core\Support\RobotBatches;

beforeEach(function () {
    $this->robot = RobotBatches::install($this->app);
});

/**
 * The status of the only client of $company.
 */
function onlyClientStatus(CompanyCode $company): ClientStatus
{
    return ClientRecord::query()->where('company', $company)->sole()->status;
}

/**
 * The lock key of each WithoutOverlapping middleware of $job.
 *
 * @param  array<int, object>  $middleware
 * @return list<string>
 */
function overlapLockKeys(object $job, array $middleware): array
{
    $keys = [];

    foreach ($middleware as $layer) {
        if ($layer instanceof WithoutOverlapping) {
            $keys[] = $layer->getLockKey($job);
        }
    }

    return $keys;
}

it('recomputes a company only while it holds the company lock, and the other company meanwhile', function () {
    // SGX: 16 days past due, overdue with its 15 grace days. SDC: 7 days past due, overdue with its 5.
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable(['fecha_vencimiento' => '2026-09-29'])]);
    $this->robot->receivables(CompanyCode::SDC, '2026-10-15', [RobotBatches::receivable(['fecha_vencimiento' => '2026-10-08'])]);

    // With 20 and 10 grace days, a recompute makes both due soon.
    CompanyRecord::query()->whereKey('SGX')->update(['grace_days' => 20]);
    CompanyRecord::query()->whereKey('SDC')->update(['grace_days' => 10]);
    app(CompanyCatalog::class)->refresh();

    $lock = Cache::lock(CompanyStatusesLock::key(CompanyCode::SGX), 60);

    expect($lock->get())->toBeTrue();

    Bus::dispatch(new RecomputeCompanyStatuses(CompanyCode::SGX));
    Bus::dispatch(new RecomputeCompanyStatuses(CompanyCode::SDC));

    expect(onlyClientStatus(CompanyCode::SGX))->toBe(ClientStatus::Overdue)
        ->and(onlyClientStatus(CompanyCode::SDC))->toBe(ClientStatus::DueSoon);

    $lock->release();
    Bus::dispatch(new RecomputeCompanyStatuses(CompanyCode::SGX));

    expect(onlyClientStatus(CompanyCode::SGX))->toBe(ClientStatus::DueSoon);
});

it('imports a cartera batch only while it holds the company lock, and the other company meanwhile', function () {
    $lock = Cache::lock(CompanyStatusesLock::key(CompanyCode::SGX), 60);

    expect($lock->get())->toBeTrue();

    $sgx = $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);
    $sdc = $this->robot->receivables(CompanyCode::SDC, '2026-10-15', [RobotBatches::receivable()]);

    expect(PortfolioLoadRecord::query()->orderBy('id')->pluck('load_id')->all())->toBe([$sdc->id])
        ->and(ClientRecord::withTrashed()->where('company', CompanyCode::SGX)->count())->toBe(0)
        ->and(ClientRecord::query()->where('company', CompanyCode::SDC)->count())->toBe(1);

    // The queue delivers the released import again once the lock is free.
    $lock->release();
    event(new BatchAccepted($sgx));

    expect(PortfolioLoadRecord::query()->orderBy('id')->pluck('load_id')->all())->toBe([$sdc->id, $sgx->id])
        ->and(ClientRecord::query()->where('company', CompanyCode::SGX)->count())->toBe(1);
});

it('gives the import and the recompute of a company one shared lock', function () {
    Queue::fake();
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);
    $this->robot->receivables(CompanyCode::SDC, '2026-10-15', [RobotBatches::receivable()]);

    $imports = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ImportReceivablesSnapshot::class)
        ->mapWithKeys(fn (CallQueuedListener $job): array => [$job->data[0]->load->company->value => overlapLockKeys($job, $job->middleware)])
        ->all();
    $recompute = fn (CompanyCode $company): array => overlapLockKeys($job = new RecomputeCompanyStatuses($company), $job->middleware());

    expect($imports)->toBe([
        'SGX' => ['laravel-queue-overlap:company-statuses:SGX'],
        'SDC' => ['laravel-queue-overlap:company-statuses:SDC'],
    ])
        ->and($recompute(CompanyCode::SGX))->toBe(['laravel-queue-overlap:company-statuses:SGX'])
        ->and($recompute(CompanyCode::SDC))->toBe(['laravel-queue-overlap:company-statuses:SDC'])
        ->and(CompanyStatusesLock::key(CompanyCode::SGX))->toBe('laravel-queue-overlap:company-statuses:SGX');
});

it('releases a job for a minute while the lock is taken and frees a lost lock after the job timeout', function () {
    $middleware = CompanyStatusesLock::middleware(CompanyCode::SDC);

    expect($middleware->shareKey)->toBeTrue()
        ->and($middleware->releaseAfter)->toBe(60)
        ->and($middleware->expiresAfter)->toBe(900)
        ->and($middleware->expiresAfter)->toBeGreaterThan((new RecomputeCompanyStatuses(CompanyCode::SDC))->timeout);
});

it('fails a job that runs past its timeout instead of retrying it', function () {
    Queue::fake();
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);

    expect(Queue::pushed(CallQueuedListener::class)->sole()->failOnTimeout)->toBeTrue()
        ->and((new RecomputeCompanyStatuses(CompanyCode::SGX))->failOnTimeout)->toBeTrue();
});

it('retries a job that waits for the lock for 30 minutes but fails it on its first exception', function () {
    $this->freezeTime();
    Queue::fake();
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);

    $import = Queue::pushed(CallQueuedListener::class)->sole();
    $recompute = new RecomputeCompanyStatuses(CompanyCode::SGX);
    $deadline = now()->addMinutes(30)->getTimestamp();

    expect($import->class)->toBe(ImportReceivablesSnapshot::class)
        ->and($import->retryUntil?->getTimestamp())->toBe($deadline)
        ->and($import->maxExceptions)->toBe(1)
        ->and($import->tries)->toBeNull()
        ->and($import->timeout)->toBe(300)
        ->and($recompute->retryUntil()->getTimestamp())->toBe($deadline)
        ->and($recompute->maxExceptions)->toBe(1)
        ->and($recompute->timeout)->toBe(300)
        ->and(property_exists($recompute, 'tries'))->toBeFalse();
});
