<?php

use App\Models\User;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Infrastructure\EloquentIngestaSink;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoadChunk;
use App\Modules\Ingesta\Infrastructure\Storage\ChunkCodec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Modules\Ingesta\Support\IngestaRows;

beforeEach(function () {
    Event::fake([BatchAccepted::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00', 'America/Bogota'));
    $this->sink = app(IngestaSink::class);
});

/**
 * @param  list<LoadRecord>  $loads
 * @return list<int>
 */
function loadIds(array $loads): array
{
    return array_map(fn (LoadRecord $load): int => $load->id, $loads);
}

it('is the IngestaSink of the application', function () {
    expect($this->sink)->toBeInstanceOf(EloquentIngestaSink::class)
        ->and(app(IngestaSink::class))->toBe($this->sink);
});

it('accepts a batch and gives the rows back in order, keyed in schema order, with exact types', function () {
    $first = IngestaRows::cartera(['codigo_sn' => 'C002', 'saldo' => '250000.00', 'dias' => -3]);
    // The same row with its keys shuffled comes back in the schema order.
    $shuffled = array_reverse(IngestaRows::cartera(), preserve_keys: true);

    $load = $this->sink->receive(IngestaRows::batch('cartera-SGX-1', [$first, $shuffled], Dataset::Receivables));

    expect($load->status)->toBe(LoadStatus::Accepted)
        ->and($load->dataset)->toBe(Dataset::Receivables)
        ->and($load->company)->toBe(CompanyCode::SGX)
        ->and($load->source)->toBe(LoadSource::Robot)
        ->and($load->cutoffDate->format('Y-m-d H:i:s e'))->toBe('2026-10-15 00:00:00 America/Bogota')
        ->and($load->receivedAt->toDateTimeString())->toBe('2026-10-15 07:00:00')
        ->and($load->rowCount)->toBe(2)
        ->and($load->receivedBy)->toBeNull()
        ->and($load->error)->toBeNull();

    $rows = iterator_to_array($this->sink->rows($load->id), preserve_keys: false);

    expect($rows)->toBe([$first, IngestaRows::cartera()])
        ->and(array_keys($rows[1]))->toBe(array_keys(IngestaRows::cartera()))
        ->and($rows[1]['slp_code'])->toBeInt()
        ->and($rows[1]['saldo'])->toBe('1000000.50')
        ->and($rows[1]['grupo'])->toBeNull();
});

it('dispatches BatchAccepted once, after the transaction commits', function () {
    $load = DB::transaction(function () {
        $load = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor()]));

        Event::assertNotDispatched(BatchAccepted::class);

        return $load;
    });

    Event::assertDispatchedTimes(BatchAccepted::class, 1);
    Event::assertDispatched(BatchAccepted::class, fn (BatchAccepted $event): bool => $event->load == $load);
});

it('returns the original load for a repeated batch id without storing or dispatching again', function () {
    $first = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor(11)]));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 13:00:00', 'America/Bogota'));

    $again = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor(12), IngestaRows::advisor(13)]));

    expect($again)->toEqual($first)
        ->and(IngestaLoad::query()->count())->toBe(1)
        ->and(IngestaLoadChunk::query()->count())->toBe(1)
        ->and(iterator_to_array($this->sink->rows($first->id), false))->toBe([IngestaRows::advisor(11)]);
    Event::assertDispatchedTimes(BatchAccepted::class, 1);
});

it('records a rejected batch, stores nothing else and accepts the same batch id once fixed', function () {
    $broken = [IngestaRows::advisor(11, ['nombre' => null]), IngestaRows::advisor(12, ['clientes' => '40'])];

    try {
        $this->sink->receive(IngestaRows::batch('asesores-1', $broken));
        $this->fail('The batch was accepted.');
    } catch (InvalidBatch $e) {
        expect($e->getMessage())->toBe('El lote tiene 2 errores.')
            ->and($e->errors())->toBe([
                'rows.0.nombre' => ['La columna nombre es obligatoria.'],
                'rows.1.clientes' => ['La columna clientes debe ser un número entero.'],
            ]);
    }

    $rejected = $this->sink->history()[0];

    expect($rejected->status)->toBe(LoadStatus::Rejected)
        ->and($rejected->error)->toBe('El lote tiene 2 errores.')
        ->and($rejected->rowCount)->toBe(2)
        ->and($rejected->total)->toBeNull()
        ->and($rejected->overdue)->toBeNull()
        ->and(iterator_to_array($this->sink->rows($rejected->id)))->toBe([])
        ->and($this->sink->latest(Dataset::Advisors, CompanyCode::SGX))->toBeNull()
        ->and(IngestaLoad::query()->sole()->errors)->toBe($e->errors())
        ->and(IngestaLoadChunk::query()->count())->toBe(0);
    Event::assertNotDispatched(BatchAccepted::class);

    $accepted = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor(11), IngestaRows::advisor(12)]));

    expect($accepted->status)->toBe(LoadStatus::Accepted)
        ->and($accepted->id)->not->toBe($rejected->id)
        ->and(loadIds($this->sink->history()))->toBe([$accepted->id, $rejected->id]);
    Event::assertDispatchedTimes(BatchAccepted::class, 1);
});

it('stores the only error message as the error of a rejected load', function () {
    expect(fn () => $this->sink->receive(IngestaRows::batch(str_repeat('x', 130), [IngestaRows::advisor()])))
        ->toThrow(InvalidBatch::class, 'El identificador del lote no puede tener más de 120 caracteres.');

    $rejected = IngestaLoad::query()->sole();

    expect($rejected->batch_id)->toBe(str_repeat('x', 120))
        ->and($rejected->accepted_batch_id)->toBeNull()
        ->and($rejected->error)->toBe('El identificador del lote no puede tener más de 120 caracteres.');
});

it('sums the cartera exactly and leaves the totals of other datasets empty', function () {
    $cartera = $this->sink->receive(IngestaRows::batch('cartera-1', [
        IngestaRows::cartera(['saldo' => '1000000.50', 'dias' => 12]),
        IngestaRows::cartera(['saldo' => '250000.00', 'dias' => -3]),
        IngestaRows::cartera(['saldo' => '400000.00', 'dias' => 0]),
        IngestaRows::cartera(['saldo' => '0.10', 'dias' => 91]),
        IngestaRows::cartera(['saldo' => '0.20', 'dias' => 1]),
    ], Dataset::Receivables));
    $advisors = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor()]));

    expect($cartera->total)->toBe(1_650_000.80)
        ->and($cartera->overdue)->toBe(1_000_000.80)
        ->and(IngestaLoad::query()->find($cartera->id)?->total)->toBe('1650000.80')
        ->and(IngestaLoad::query()->find($cartera->id)?->overdue)->toBe('1000000.80')
        ->and($advisors->total)->toBeNull()
        ->and($advisors->overdue)->toBeNull();
});

it('splits large loads into chunks and reads them back in order, one chunk at a time', function () {
    $rows = array_map(fn (int $code): array => IngestaRows::advisor($code), range(1, 1201));

    $load = $this->sink->receive(IngestaRows::batch('asesores-1', $rows));

    expect(IngestaLoadChunk::query()->where('load_id', $load->id)->orderBy('sequence')->pluck('row_count')->all())->toBe([500, 500, 201])
        ->and(iterator_to_array($this->sink->rows($load->id), false))->toBe($rows);

    $payloads = 0;
    DB::listen(function (QueryExecuted $query) use (&$payloads): void {
        $payloads += str_contains($query->sql, 'payload') ? 1 : 0;
    });

    foreach ($this->sink->rows($load->id) as $row) {
        expect($row)->toBe($rows[0]);
        break;
    }

    expect($payloads)->toBe(1);
});

it('accepts an empty batch of a dataset that allows it', function () {
    $load = $this->sink->receive(IngestaRows::batch('ventas-1', [], Dataset::Sales));

    expect($load->status)->toBe(LoadStatus::Accepted)
        ->and($load->rowCount)->toBe(0)
        ->and(iterator_to_array($this->sink->rows($load->id)))->toBe([]);
});

it('names the uploader of a manual load', function () {
    $user = User::factory()->create(['name' => 'Laura Gómez', 'email' => 'laura@segurex.com']);

    $load = $this->sink->receive(IngestaRows::batch('manual-1', [IngestaRows::advisor()], source: LoadSource::Manual, receivedBy: $user->id));

    expect($load->source)->toBe(LoadSource::Manual)
        ->and($load->receivedBy)->toEqual(new UserRef($user->id, 'Laura Gómez', 'laura@segurex.com'))
        ->and($this->sink->history()[0]->receivedBy)->toEqual($load->receivedBy)
        ->and($this->sink->latest(Dataset::Advisors, CompanyCode::SGX)?->receivedBy)->toEqual($load->receivedBy);
});

it('finds the latest accepted snapshot by cutoff date, then by reception', function () {
    $this->sink->receive(IngestaRows::batch('b1', [IngestaRows::advisor()], cutoffDate: '2026-10-13'));
    $this->sink->receive(IngestaRows::batch('b2', [IngestaRows::advisor()], cutoffDate: '2026-10-14'));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:00:00', 'America/Bogota'));
    $newest = $this->sink->receive(IngestaRows::batch('b3', [IngestaRows::advisor()], cutoffDate: '2026-10-14'));
    $this->sink->receive(IngestaRows::batch('b4', [IngestaRows::advisor()], cutoffDate: '2026-10-12'));
    $this->sink->receive(IngestaRows::batch('b5', [IngestaRows::advisor()], company: CompanyCode::SDC, cutoffDate: '2026-10-15'));
    $this->sink->receive(IngestaRows::batch('b6', [IngestaRows::cartera()], Dataset::Receivables, cutoffDate: '2026-10-15'));
    rescue(fn () => $this->sink->receive(IngestaRows::batch('b7', [], cutoffDate: '2026-10-15')), report: false);

    expect($this->sink->latest(Dataset::Advisors, CompanyCode::SGX)?->id)->toBe($newest->id)
        ->and($this->sink->latest(Dataset::Sales, CompanyCode::SGX))->toBeNull();
});

it('breaks a tie on the same second by id', function () {
    $this->sink->receive(IngestaRows::batch('b1', [IngestaRows::advisor()]));
    $second = $this->sink->receive(IngestaRows::batch('b2', [IngestaRows::advisor()]));

    expect($this->sink->latest(Dataset::Advisors, CompanyCode::SGX)?->id)->toBe($second->id)
        ->and(loadIds($this->sink->history()))->toBe([$second->id, $second->id - 1]);
});

it('lists the history newest first with filters and a limit', function () {
    $b1 = $this->sink->receive(IngestaRows::batch('b1', [IngestaRows::cartera()], Dataset::Receivables));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:00:00', 'America/Bogota'));
    $b2 = $this->sink->receive(IngestaRows::batch('b2', [IngestaRows::advisor()]));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'America/Bogota'));
    $b3 = $this->sink->receive(IngestaRows::batch('b3', [IngestaRows::cartera()], Dataset::Receivables, company: CompanyCode::SDC));

    expect(loadIds($this->sink->history()))->toBe([$b3->id, $b2->id, $b1->id])
        ->and(loadIds($this->sink->history(Dataset::Receivables)))->toBe([$b3->id, $b1->id])
        ->and(loadIds($this->sink->history(company: CompanyCode::SGX)))->toBe([$b2->id, $b1->id])
        ->and(loadIds($this->sink->history(Dataset::Receivables, CompanyCode::SGX)))->toBe([$b1->id])
        ->and(loadIds($this->sink->history(limit: 1)))->toBe([$b3->id])
        ->and($this->sink->history()[2])->toEqual($b1);
});

it('refuses a history limit below 1', function () {
    $this->sink->history(limit: 0);
})->throws(InvalidArgumentException::class, 'The limit must be at least 1, 0 given.');

it('reports unknown loads', function () {
    $this->sink->rows(404);
})->throws(RecordNotFound::class, 'No load with id 404 exists in the current scope.');

it('refuses to read a chunk that does not match its load columns', function () {
    $load = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor()]));
    IngestaLoadChunk::query()->update(['payload' => ChunkCodec::encode([[1, 2]])]);

    iterator_to_array($this->sink->rows($load->id));
})->throws(UnexpectedValueException::class);

it('returns the winner when a concurrent copy of the batch is accepted first', function () {
    // Another request accepts the same batch id right after this one looked
    // for it and before it inserts: the unique accepted_batch_id collides.
    $winner = null;
    DB::listen(function (QueryExecuted $query) use (&$winner): void {
        if ($winner === null && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'accepted_batch_id')) {
            $winner = 0;
            $winner = DB::table('ingesta_loads')->insertGetId([
                'batch_id' => 'asesores-1', 'accepted_batch_id' => 'asesores-1', 'dataset' => 'asesores',
                'company' => 'SGX', 'cutoff_date' => '2026-10-15', 'source' => 'robot', 'status' => 'aceptada',
                'row_count' => 0, 'columns' => '[]', 'received_at' => '2026-10-15 06:59:00',
            ]);
        }
    });

    $load = $this->sink->receive(IngestaRows::batch('asesores-1', [IngestaRows::advisor()]));

    expect($load->id)->toBe($winner)
        ->and($load->rowCount)->toBe(0)
        ->and(IngestaLoad::query()->count())->toBe(1)
        ->and(IngestaLoadChunk::query()->count())->toBe(0);
    Event::assertNotDispatched(BatchAccepted::class);
});
