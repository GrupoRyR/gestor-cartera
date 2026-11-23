<?php

use App\Models\User;
use App\Modules\Core\Application\Commitments\RemainingBalanceNotice;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * The notice a partial payment leaves (docs/arquitectura.md §6.5): someone
 * must create a new commitment for the remaining balance. It goes, from the
 * client's current data, to whoever writes its commitments: in SGX the
 * user of its advisor, else its gestor, else the SGX portfolio managers; in
 * SDC its gestor, else the SDC portfolio analysts. Creating any commitment
 * for the client resolves it.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    $this->granos = CarteraRecords::client('Granos Giraldo', ['company' => CompanyCode::SDC, 'advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    $this->gestor = TeamUsers::member('Gloria Gestora', 'gloria.gestora@segurex.com', null);
    TeamUsers::advisor(CompanyCode::SGX, 30, 'Asesor sin usuario');
});

/**
 * Records a partial payment of 1.000.000 on a new 2.500.000 commitment of
 * $client, as $actor, and returns the commitment.
 */
function payPartOfCommitment(ClientRecord $client, User $actor): CommitmentRecord
{
    $commitment = CommitmentRecords::commitment($client, $actor, '2026-10-20', '2500000.00');
    test()->actingAs($actor);
    CommitmentRecords::service()->markPartiallyPaid($commitment->id, 1_000_000);

    return $commitment;
}

/**
 * The users who received a remaining balance notice, by id.
 *
 * @return list<int>
 */
function remainingNoticeRecipients(): array
{
    return NoticeRecord::query()->where('kind', RemainingBalanceNotice::KIND)->orderBy('user_id')->pluck('user_id')->all();
}

it('tells the user of the SGX client\'s advisor to create a commitment for the balance', function () {
    $commitment = payPartOfCommitment($this->agro, $this->people['mariaPaula']);

    $notice = NoticeRecord::query()->sole();

    expect($notice->user_id)->toBe($this->people['carlos']->id)
        ->and($notice->kind)->toBe('commitment.remaining')
        ->and($notice->key)->toBe("commitment.remaining:{$this->agro->id}")
        ->and(RemainingBalanceNotice::key($this->agro->id))->toBe("commitment.remaining:{$this->agro->id}")
        ->and($notice->company)->toBe('SGX')
        ->and($notice->subject_type)->toBe('client')
        ->and($notice->subject_id)->toBe($this->agro->id)
        ->and($notice->title)->toBe("Agro Arango: abonó $\u{00A0}1.000.000, queda $\u{00A0}1.500.000")
        ->and($notice->body)->toBe('Crea un nuevo compromiso por el saldo.')
        ->and($notice->data)->toEqual(['commitment_id' => $commitment->id, 'paid_amount' => 1_000_000, 'remaining' => 1_500_000])
        ->and($notice->read_at)->toBeNull()
        ->and($notice->resolved_at)->toBeNull();
});

it('shortens a long client name to fit the title', function () {
    $this->agro->update(['name' => str_repeat('Agroindustrial ', 12)]);

    payPartOfCommitment($this->agro, $this->people['carlos']);

    $title = NoticeRecord::query()->sole()->title;

    expect(mb_strlen($title))->toBe(160)
        ->and($title)->toStartWith('Agroindustrial Agroindustrial')
        ->and($title)->toEndWith("…: abonó $\u{00A0}1.000.000, queda $\u{00A0}1.500.000");
});

it('does not leave out the user who recorded the payment', function () {
    payPartOfCommitment($this->agro, $this->people['carlos']);

    expect(remainingNoticeRecipients())->toBe([$this->people['carlos']->id]);
});

it('follows the client\'s current advisor, not the one the commitment was made with', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20', '2500000.00');
    $this->agro->update(['advisor_code' => 20]);
    $this->actingAs($this->people['miguel']);

    CommitmentRecords::service()->markPartiallyPaid($commitment->id, 1_000_000);

    expect(remainingNoticeRecipients())->toBe([$this->people['diana']->id]);
});

it('falls back to the gestor when the SGX advisor has no active user', function (int $advisorCode, bool $deactivate) {
    $this->agro->update(['advisor_code' => $advisorCode, 'manager_user_id' => $this->gestor->id]);

    if ($deactivate) {
        $this->people['carlos']->update(['is_active' => false]);
    }

    payPartOfCommitment($this->agro, $this->people['miguel']);

    expect(remainingNoticeRecipients())->toBe([$this->gestor->id]);
})->with([
    'an advisor without a user' => [30, false],
    'an advisor whose user is inactive' => [12, true],
]);

it('falls back to the SGX portfolio managers without an advisor user nor a gestor', function () {
    $this->agro->update(['advisor_code' => 30]);
    $sgxManager = TeamUsers::member('Sara Gerente', 'sara.gerente@segurex.com', Role::PortfolioManager, [CompanyCode::SGX]);
    TeamUsers::member('Samuel Derivados', 'samuel.gerente@derivadoscol.com', Role::PortfolioManager, [CompanyCode::SDC]);
    TeamUsers::member('Iván Inactivo', 'ivan.gerente@segurex.com', Role::PortfolioManager, [CompanyCode::SGX], active: false);

    payPartOfCommitment($this->agro, $this->people['mariaPaula']);

    expect(remainingNoticeRecipients())->toBe([$this->people['miguel']->id, $sgxManager->id]);
});

it('tells the gestor of an SDC client', function () {
    $this->granos->update(['manager_user_id' => $this->gestor->id]);

    payPartOfCommitment($this->granos, $this->people['miguel']);

    expect(remainingNoticeRecipients())->toBe([$this->gestor->id]);
});

it('falls back to the SDC portfolio analysts without a gestor', function () {
    $sdcAnalyst = TeamUsers::member('Daniela Analista', 'daniela.analista@derivadoscol.com', Role::PortfolioAnalyst, [CompanyCode::SDC]);
    TeamUsers::member('Sofía Segurex', 'sofia.analista@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);

    payPartOfCommitment($this->granos, $this->people['miguel']);

    expect(remainingNoticeRecipients())->toBe([$this->people['mariaPaula']->id, $sdcAnalyst->id]);
});

it('sends nothing when nobody can take the balance', function () {
    $this->people['mariaPaula']->update(['is_active' => false]);

    $commitment = payPartOfCommitment($this->granos, $this->people['miguel']);

    expect(NoticeRecord::query()->count())->toBe(0)
        ->and(CommitmentRecords::stored($commitment)->paid_amount)->toBe('1000000.00');
});

it('leaves no notice when the payment is rolled back', function () {
    $commitment = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20', '2500000.00');
    $this->actingAs($this->people['carlos']);

    expect(fn () => DB::transaction(function () use ($commitment): void {
        CommitmentRecords::service()->markPartiallyPaid($commitment->id, 1_000_000);

        throw new RuntimeException('The caller failed afterwards.');
    }))->toThrow(RuntimeException::class)
        ->and(NoticeRecord::query()->count())->toBe(0)
        ->and(CommitmentRecords::stored($commitment)->paid_amount)->toBeNull();
});

it('resolves the open notices of the client when any commitment is created for it', function () {
    $bodega = CarteraRecords::client('Bodega Botero', ['advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    payPartOfCommitment($this->agro, $this->people['carlos']);
    payPartOfCommitment($bodega, $this->people['carlos']);

    CommitmentRecords::service()->create(new NewCommitment($this->agro->id, CarteraRecords::at('2026-10-30'), 1_500_000));

    expect(NoticeRecord::query()->where('subject_id', $this->agro->id)->sole()->resolved_at?->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and(NoticeRecord::query()->where('subject_id', $bodega->id)->sole()->resolved_at)->toBeNull();
});
