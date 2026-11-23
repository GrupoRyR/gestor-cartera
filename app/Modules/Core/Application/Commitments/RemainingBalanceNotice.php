<?php

namespace App\Modules\Core\Application\Commitments;

use App\Models\User;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Support\Formatting\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The notice a partial payment leaves (docs/arquitectura.md §6.5): «Agro
 * Arango: abonó $ 1.000.000, queda $ 1.500.000», asking for a new
 * commitment for the balance. Its key groups every such notice of one
 * client, so creating any commitment for the client resolves them.
 *
 * It goes to whoever writes the client's commitments, read from the
 * client's current data (not the commitment's advisor snapshot), skipping
 * inactive and deleted users; the user who recorded the payment is not
 * left out. Where the advisor writes (SGX, CommitmentWriteRule): the user
 * of the client's advisor, else the client's gestor, else every portfolio
 * manager assigned to the company. Elsewhere (SDC): the client's gestor,
 * else every portfolio analyst assigned to the company.
 */
final readonly class RemainingBalanceNotice
{
    public const string KIND = 'commitment.remaining';

    /** Length of notices.title. */
    private const int TITLE_LENGTH = 160;

    public function __construct(private CommitmentWriteRule $rule) {}

    /**
     * The key of the remaining balance notices of the client.
     */
    public static function key(int $clientId): string
    {
        return self::KIND.":{$clientId}";
    }

    /**
     * The notice for the partially paid $commitment of $client, or null
     * when nobody can take the balance.
     */
    public function for(ClientRecord $client, CommitmentRecord $commitment, float $remaining): ?Aviso
    {
        $recipients = $this->recipients($client);

        if ($recipients === []) {
            return null;
        }

        return new Aviso(
            recipients: $recipients,
            kind: self::KIND,
            title: self::title($client->name, Money::format($commitment->paid_amount), Money::format($remaining)),
            body: self::text('core::compromisos.aviso_saldo.cuerpo', []),
            company: $client->company->value,
            subjectType: 'client',
            subjectId: $client->id,
            key: self::key($client->id),
            data: [
                'commitment_id' => $commitment->id,
                'paid_amount' => (float) $commitment->paid_amount,
                'remaining' => $remaining,
            ],
        );
    }

    /**
     * Ids of the users who take the balance of $client, in id order.
     *
     * @return list<int>
     */
    public function recipients(ClientRecord $client): array
    {
        if (CommitmentWriteRule::advisorWrites($client->company)) {
            $advisor = $this->advisorUser($client);

            if ($advisor !== null) {
                return [$advisor];
            }
        }

        $gestor = $client->manager_user_id === null
            ? null
            : self::enabledUsers()->whereKey($client->manager_user_id)->value('id');

        if (is_int($gestor)) {
            return [$gestor];
        }

        $role = CommitmentWriteRule::advisorWrites($client->company) ? Role::PortfolioManager : Role::PortfolioAnalyst;

        /** @var list<int> */
        return self::enabledUsers()
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role->value))
            ->whereIn('id', fn (QueryBuilder $assigned) => $assigned->select('user_id')->from('company_user')->where('company', $client->company->value))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The enabled user linked to the client's current advisor, when the
     * write rule lets them write its commitments.
     */
    private function advisorUser(ClientRecord $client): ?int
    {
        if ($client->advisor_code === null) {
            return null;
        }

        $userId = AdvisorRecord::query()
            ->where('company', $client->company)
            ->where('code', $client->advisor_code)
            ->value('user_id');
        $user = $userId === null ? null : self::enabledUsers()->find($userId);

        return $user instanceof User && $this->rule->allows($user, $client) ? (int) $user->getKey() : null;
    }

    /**
     * Active users; soft-deleted ones are already left out.
     *
     * @return Builder<User>
     */
    private static function enabledUsers(): Builder
    {
        return User::query()->where('is_active', true);
    }

    /**
     * The title, with the client's name shortened when it would not fit.
     */
    private static function title(string $client, string $paid, string $remaining): string
    {
        $title = self::text('core::compromisos.aviso_saldo.titulo', ['cliente' => $client, 'pagado' => $paid, 'saldo' => $remaining]);
        $excess = mb_strlen($title) - self::TITLE_LENGTH;

        if ($excess <= 0) {
            return $title;
        }

        $client = rtrim(mb_substr($client, 0, max(1, mb_strlen($client) - $excess - 1))).'…';

        return self::text('core::compromisos.aviso_saldo.titulo', ['cliente' => $client, 'pagado' => $paid, 'saldo' => $remaining]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
