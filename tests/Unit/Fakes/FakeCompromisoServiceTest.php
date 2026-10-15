<?php

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\FakeCompromisoService;
use Tests\Fakes\Make;

/**
 * @param  iterable<int, Commitment>  $commitments
 * @return list<int>
 */
function commitmentIds(iterable $commitments): array
{
    $ids = [];

    foreach ($commitments as $commitment) {
        $ids[] = $commitment->id;
    }

    return $ids;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:30:00'));

    $this->service = (new FakeCompromisoService)
        ->knowClient(10, 'Ferretería El Tornillo SAS', advisorCode: 11)
        ->knowClient(20, 'Andina de Químicos SAS', CompanyCode::SDC, 5);
});

it('creates pending commitments as the acting user', function () {
    $commitment = $this->service->create(new NewCommitment(
        clientId: 10,
        dueDate: CarbonImmutable::parse('2026-10-20'),
        amount: 2_000_000,
        notes: 'Paga el lunes',
    ));

    expect($commitment->id)->toBe(1)
        ->and($commitment->status)->toBe(CommitmentStatus::Pending)
        ->and($commitment->company)->toBe(CompanyCode::SGX)
        ->and($commitment->clientName)->toBe('Ferretería El Tornillo SAS')
        ->and($commitment->advisorCode)->toBe(11)
        ->and($commitment->amount)->toBe(2_000_000.0)
        ->and($commitment->notes)->toBe('Paga el lunes')
        ->and($commitment->createdBy)->toEqual(new UserRef(1, 'Usuario de prueba', 'prueba@example.com'))
        ->and($commitment->createdAt->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($commitment->fulfilledAt)->toBeNull()
        ->and($this->service->find(1))->toEqual($commitment);
});

it('numbers commitments and records the acting user', function () {
    $analyst = new UserRef(7, 'María Paula', 'mp@example.com');
    $this->service->create(new NewCommitment(10, CarbonImmutable::parse('2026-10-20'), 100));

    $second = $this->service->actingAs($analyst)->create(new NewCommitment(20, CarbonImmutable::parse('2026-10-21'), 200));

    expect($second->id)->toBe(2)
        ->and($second->company)->toBe(CompanyCode::SDC)
        ->and($second->createdBy)->toBe($analyst);
});

it('refuses commitments for unknown clients', function () {
    expect(fn () => $this->service->create(new NewCommitment(99, CarbonImmutable::parse('2026-10-20'), 100)))
        ->toThrow(RecordNotFound::class, 'client');
});

it('refuses amounts that are not positive', function () {
    expect(fn () => $this->service->create(new NewCommitment(10, CarbonImmutable::parse('2026-10-20'), 0)))
        ->toThrow(InvalidArgumentException::class);
});

it('can deny authorization to every write', function () {
    $this->service->denyAuthorization();

    expect(fn () => $this->service->create(new NewCommitment(10, CarbonImmutable::parse('2026-10-20'), 100)))
        ->toThrow(AuthorizationException::class);

    $this->service->assertNothingCreated();
});

it('knows the clients of the cartera fake and honours its scope', function () {
    $mine = Make::client(name: 'Cliente propio', advisor: Make::advisor(code: 22));
    $other = Make::client(name: 'Cliente ajeno', advisor: Make::advisor(code: 33));
    $cartera = (new FakeCarteraRepository)
        ->addClient(Make::clientDetail($mine))
        ->addClient(Make::clientDetail($other))
        ->restrictToAdvisor(22);
    $service = new FakeCompromisoService($cartera);
    $foreign = Make::commitment(clientId: $other->id, clientName: 'Cliente ajeno');
    $service->add($foreign);

    $created = $service->create(new NewCommitment($mine->id, CarbonImmutable::parse('2026-10-20'), 100));

    expect($created->clientName)->toBe('Cliente propio')
        ->and($created->advisorCode)->toBe(22)
        ->and(fn () => $service->create(new NewCommitment($other->id, CarbonImmutable::parse('2026-10-20'), 100)))
        ->toThrow(RecordNotFound::class)
        ->and($service->find($foreign->id))->toBeNull()
        ->and(commitmentIds($service->paginate(new CommitmentQuery)->items()))->toBe([$created->id]);
});

it('updates only the given fields of a pending commitment', function () {
    $commitment = $this->service->create(new NewCommitment(10, CarbonImmutable::parse('2026-10-20'), 100, 'Nota'));

    $updated = $this->service->update($commitment->id, new CommitmentChanges(amount: 150));

    expect($updated->amount)->toBe(150.0)
        ->and($updated->dueDate)->toEqual($commitment->dueDate)
        ->and($updated->notes)->toBe('Nota')
        ->and($this->service->find($commitment->id))->toEqual($updated);
});

it('refuses to update a commitment that is not pending', function () {
    $broken = Make::commitment(clientId: 10, status: CommitmentStatus::Broken);
    $this->service->add($broken);

    expect(fn () => $this->service->update($broken->id, new CommitmentChanges(amount: 150)))
        ->toThrow(DomainException::class);
});

it('marks pending and broken commitments as fulfilled', function () {
    $broken = Make::commitment(clientId: 10, status: CommitmentStatus::Broken);
    $this->service->add($broken);

    $fulfilled = $this->service->markFulfilled($broken->id, 'Pagó tarde');

    expect($fulfilled->status)->toBe(CommitmentStatus::Fulfilled)
        ->and($fulfilled->fulfilledAt?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and(fn () => $this->service->markFulfilled($broken->id))->toThrow(DomainException::class);
});

it('cancels with a reason and keeps final statuses final', function () {
    $commitment = $this->service->create(new NewCommitment(10, CarbonImmutable::parse('2026-10-20'), 100));

    expect(fn () => $this->service->cancel($commitment->id, '  '))->toThrow(InvalidArgumentException::class);

    $cancelled = $this->service->cancel($commitment->id, 'Renegociado');

    expect($cancelled->status)->toBe(CommitmentStatus::Cancelled)
        ->and($this->service->cancellationReason($commitment->id))->toBe('Renegociado')
        ->and(fn () => $this->service->cancel($commitment->id, 'Otra vez'))->toThrow(DomainException::class)
        ->and(fn () => $this->service->markFulfilled($commitment->id))->toThrow(DomainException::class);
});

it('reports unknown commitments', function (string $write) {
    $service = $this->service;

    expect($service->find(404))->toBeNull()
        ->and(fn () => match ($write) {
            'update' => $service->update(404, new CommitmentChanges),
            'markFulfilled' => $service->markFulfilled(404),
            'cancel' => $service->cancel(404, 'No existe'),
        })->toThrow(RecordNotFound::class, 'commitment');
})->with(['update', 'markFulfilled', 'cancel']);

it('filters and sorts commitments', function () {
    $this->service->add(
        $a = Make::commitment(clientId: 10, advisorCode: 11, amount: 300, dueDate: CarbonImmutable::parse('2026-10-16')),
        $b = Make::commitment(clientId: 10, advisorCode: 11, amount: 100, dueDate: CarbonImmutable::parse('2026-10-25'), status: CommitmentStatus::Broken),
        $c = Make::commitment(company: CompanyCode::SDC, clientId: 20, advisorCode: 5, amount: 200, dueDate: CarbonImmutable::parse('2026-10-20')),
    );
    $service = $this->service;

    expect(commitmentIds($service->paginate(new CommitmentQuery)->items()))->toBe([$a->id, $c->id, $b->id])
        ->and(commitmentIds($service->paginate(new CommitmentQuery(sort: 'amount', direction: 'desc'))->items()))->toBe([$a->id, $c->id, $b->id])
        ->and(commitmentIds($service->paginate(new CommitmentQuery(advisorCode: 11))->items()))->toBe([$a->id, $b->id])
        ->and(commitmentIds($service->paginate(new CommitmentQuery(statuses: [CommitmentStatus::Broken]))->items()))->toBe([$b->id])
        ->and(commitmentIds($service->paginate(new CommitmentQuery(companies: [CompanyCode::SDC]))->items()))->toBe([$c->id])
        ->and(commitmentIds($service->paginate(new CommitmentQuery(
            dueFrom: CarbonImmutable::parse('2026-10-16'),
            dueTo: CarbonImmutable::parse('2026-10-20'),
        ))->items()))->toBe([$a->id, $c->id])
        ->and($service->paginate(new CommitmentQuery, perPage: 2)->total())->toBe(3)
        ->and($service->all())->toHaveCount(3);
});

it('asserts created commitments', function () {
    $this->service->add(Make::commitment(clientId: 10, amount: 999));

    $this->service->assertNothingCreated();
    expect(fn () => $this->service->assertCreated())->toThrow(AssertionFailedError::class);

    $this->service->create(new NewCommitment(10, CarbonImmutable::parse('2026-10-20'), 100));

    $this->service->assertCreated();
    $this->service->assertCreated(fn (Commitment $commitment): bool => $commitment->amount === 100.0);
    expect(fn () => $this->service->assertCreated(fn (Commitment $commitment): bool => $commitment->amount === 999.0))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->service->assertNothingCreated())->toThrow(AssertionFailedError::class);
});
