<?php

use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Commitments\EloquentCompromisoService;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * Who writes commitments (docs/arquitectura.md §6.5): in SGX the advisor of
 * the client, in SDC the portfolio analyst; the portfolio manager and analyst
 * read them all. Agro Arango is an overdue SGX client of advisor 12
 * (Carlos); Granos Giraldo, an overdue SDC client of advisor 12 (Hernán).
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    $this->granos = CarteraRecords::client('Granos Giraldo', [
        'company' => CompanyCode::SDC,
        'advisor_code' => 12,
        'status' => ClientStatus::Overdue,
    ]);
    $this->dueDate = CarteraRecords::at('2026-10-22');
});

/**
 * Creates, edits, fulfils one and cancels another commitment of $client as
 * the signed-in user.
 *
 * @return list<CommitmentStatus>
 */
function writeCommitmentsEveryWay(int $clientId, CarbonImmutable $dueDate): array
{
    $service = CommitmentRecords::service();
    $first = $service->create(new NewCommitment($clientId, $dueDate, 2_500_000));
    $service->update($first->id, new CommitmentChanges(amount: 3_000_000));
    $second = $service->create(new NewCommitment($clientId, $dueDate->addWeek(), 1_000_000));

    return [
        $service->markFulfilled($first->id)->status,
        $service->cancel($second->id, 'Se renegoció')->status,
    ];
}

it('is the CompromisoService of the container', function () {
    expect(app(CompromisoService::class))->toBeInstanceOf(EloquentCompromisoService::class);
});

it('lets the SGX advisor of the client create, edit, fulfil and cancel its commitments', function () {
    $this->actingAs($this->people['carlos']);

    expect(writeCommitmentsEveryWay($this->agro->id, $this->dueDate))->toBe([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled])
        ->and(CommitmentRecord::query()->count())->toBe(2);
});

it('lets the portfolio analyst create, edit, fulfil and cancel the commitments of SDC', function () {
    $this->actingAs($this->people['mariaPaula']);

    expect(writeCommitmentsEveryWay($this->granos->id, $this->dueDate))->toBe([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled])
        ->and(CommitmentRecord::query()->count())->toBe(2);
});

it('refuses anyone else who sees the client, without writing anything', function (string $writer, string $client) {
    $existing = CommitmentRecords::commitment($this->{$client}, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people[$writer]);
    $service = CommitmentRecords::service();

    expect(fn () => $service->create(new NewCommitment($this->{$client}->id, $this->dueDate, 2_500_000)))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->update($existing->id, new CommitmentChanges(amount: 5)))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->markFulfilled($existing->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->cancel($existing->id, 'Error'))->toThrow(AuthorizationException::class)
        ->and(CommitmentRecord::query()->count())->toBe(1)
        ->and(CommitmentRecords::stored($existing)->status)->toBe(CommitmentStatus::Pending)
        ->and(CommitmentRecords::stored($existing)->amount)->toBe('1000000.00')
        ->and($this->{$client}->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(AuditLogRecord::query()->count())->toBe(0);
})->with([
    'the SGX portfolio analyst' => ['mariaPaula', 'agro'],
    'the SGX portfolio manager' => ['miguel', 'agro'],
    'the SDC advisor of the client' => ['hernan', 'granos'],
    'the SDC portfolio manager' => ['miguel', 'granos'],
]);

it('refuses an admin and the sales manager of the advisor\'s team', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX);
    AdvisorRecord::teamOf($user)->attach(AdvisorRecord::query()->where('company', CompanyCode::SGX)->where('code', 12)->sole());
    $this->actingAs($user);

    expect(fn () => CommitmentRecords::service()->create(new NewCommitment($this->agro->id, $this->dueDate, 2_500_000)))
        ->toThrow(AuthorizationException::class);
})->with([Role::Admin, Role::SalesManager]);

it('refuses a sales manager linked to the advisor without the asesor role', function () {
    $user = CoreUsers::create(Role::SalesManager, CompanyCode::SGX);
    AdvisorRecord::query()->where('company', CompanyCode::SGX)->where('code', 20)->update(['user_id' => $user->id]);
    $diego = CarteraRecords::client('Distribuciones Duque', ['advisor_code' => 20]);
    $this->actingAs($user);

    expect(fn () => CommitmentRecords::service()->create(new NewCommitment($diego->id, $this->dueDate, 2_500_000)))
        ->toThrow(AuthorizationException::class);
});

it('checks the writer before the amount and the reason', function () {
    $existing = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['mariaPaula']);
    $service = CommitmentRecords::service();

    expect(fn () => $service->create(new NewCommitment($this->agro->id, $this->dueDate, 0)))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->update($existing->id, new CommitmentChanges(amount: -1)))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->cancel($existing->id, '  '))->toThrow(AuthorizationException::class);
});

/*
 * An advisor never sees another advisor's clients (§6.2), so their
 * commitments do not exist for them: RecordNotFound, not
 * AuthorizationException, which would reveal that the id exists.
 */
it('hides the clients of another advisor, so their commitments do not exist for them', function () {
    $existing = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->actingAs($this->people['diana']);
    $service = CommitmentRecords::service();

    expect(fn () => $service->create(new NewCommitment($this->agro->id, $this->dueDate, 2_500_000)))
        ->toThrow(RecordNotFound::class, "No client with id {$this->agro->id} exists in the current scope.")
        ->and(fn () => $service->update($existing->id, new CommitmentChanges(amount: 5)))
        ->toThrow(RecordNotFound::class, "No commitment with id {$existing->id} exists in the current scope.")
        ->and(fn () => $service->markFulfilled($existing->id))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->cancel($existing->id, 'Error'))->toThrow(RecordNotFound::class)
        ->and($service->find($existing->id))->toBeNull()
        ->and($service->paginate(new CommitmentQuery)->total())->toBe(0)
        ->and(CommitmentRecords::stored($existing)->status)->toBe(CommitmentStatus::Pending);
});

it('treats guests as if no commitment existed', function () {
    $existing = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $service = CommitmentRecords::service();

    expect(fn () => $service->create(new NewCommitment($this->agro->id, $this->dueDate, 2_500_000)))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->markFulfilled($existing->id))->toThrow(RecordNotFound::class)
        ->and($service->find($existing->id))->toBeNull()
        ->and($service->paginate(new CommitmentQuery)->total())->toBe(0);
});

it('refuses missing and deleted clients and their commitments', function () {
    $existing = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $this->agro->delete();
    $this->actingAs($this->people['carlos']);
    $service = CommitmentRecords::service();

    expect(fn () => $service->create(new NewCommitment($this->agro->id, $this->dueDate, 2_500_000)))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->create(new NewCommitment(999, $this->dueDate, 2_500_000)))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->update($existing->id, new CommitmentChanges(amount: 5)))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->markFulfilled($existing->id))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->cancel($existing->id, 'Error'))->toThrow(RecordNotFound::class)
        ->and(fn () => $service->markFulfilled(999))->toThrow(RecordNotFound::class, 'No commitment with id 999 exists in the current scope.')
        ->and($service->find($existing->id))->toBeNull()
        ->and(ActivityRecord::query()->count())->toBe(0);
});

it('lets the portfolio manager and analyst read every commitment, and each advisor only theirs', function () {
    $dorado = CarteraRecords::client('Distribuciones Dorado', ['advisor_code' => 20]);
    $agro = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $dorado = CommitmentRecords::commitment($dorado, $this->people['diana'], '2026-10-21');
    $granos = CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-22');
    $readable = function (string $who): array {
        $this->actingAs($this->people[$who]);
        $query = new CommitmentQuery(companies: [CompanyCode::SGX, CompanyCode::SDC]);

        return CommitmentRecords::idsOf(CommitmentRecords::service()->paginate($query)->items());
    };

    expect($readable('miguel'))->toBe(CommitmentRecords::ids($agro, $dorado, $granos))
        ->and($readable('mariaPaula'))->toBe(CommitmentRecords::ids($agro, $dorado, $granos))
        ->and($readable('carlos'))->toBe(CommitmentRecords::ids($agro))
        ->and($readable('diana'))->toBe(CommitmentRecords::ids($dorado))
        ->and($readable('hernan'))->toBe(CommitmentRecords::ids($granos));
});

it('finds a commitment of any company the user may work in, not only the active one', function () {
    $granos = CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-22');
    $this->actingAs($this->people['mariaPaula']);
    CarteraRecords::switchTo(CompanyCode::SGX);

    expect(CommitmentRecords::service()->find($granos->id)?->id)->toBe($granos->id)
        ->and(CommitmentRecords::service()->markFulfilled($granos->id)->status)->toBe(CommitmentStatus::Fulfilled);
});
