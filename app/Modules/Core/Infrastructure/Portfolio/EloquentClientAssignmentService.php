<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Access\CompanyAccess;
use App\Modules\Core\Infrastructure\Access\PortfolioScope;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * ClientAssignmentService over clients.manager_user_id, for the signed-in
 * user.
 *
 * Checks the permission first, so a user without it gets the same refusal
 * for any input. Then, in one transaction, reads and locks the rows of the
 * clients (id, company and manager only, CHUNK ids per statement), checks
 * the manager against their companies and writes only the clients whose
 * manager changes: CHUNK rows per update and per insert in their logs, and
 * one audit entry each.
 *
 * The log entry is an ActivityType::System entry written by the user: it
 * says who assigned, but it is bookkeeping, not work on the client, so it
 * never moves the client's last activity (LastActivity), and a bulk
 * assignment never hides clients from "vencidos sin gestión".
 */
final class EloquentClientAssignmentService implements ClientAssignmentService
{
    public const string PERMISSION = 'core.clients.assign';

    /** Ids read and rows written per statement. */
    public const int CHUNK = 500;

    /** The roles a client may be assigned to. */
    private const array ASSIGNEE_ROLES = [Role::PortfolioManager, Role::PortfolioAnalyst];

    public function __construct(
        private readonly Gate $gate,
        private readonly AuthFactory $auth,
        private readonly CurrentCompany $currentCompany,
        private readonly ClientReach $reach,
        private readonly CompanyAccess $access,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
    ) {}

    public function assignees(): array
    {
        $this->gate->authorize(self::PERMISSION);

        $assignees = [];

        foreach ($this->candidates()->orderByRaw('LOWER(name) asc')->orderBy('id')->get() as $user) {
            if ($this->worksIn($user) !== []) {
                $assignees[] = new UserRef($user->id, $user->name, $user->email);
            }
        }

        return $assignees;
    }

    public function assign(array $clientIds, ?int $managerUserId): int
    {
        $this->gate->authorize(self::PERMISSION);

        $ids = array_values(array_unique($clientIds));
        sort($ids);

        if ($ids === []) {
            return 0;
        }

        return $this->database->transaction(function () use ($ids, $managerUserId): int {
            $scope = $this->reach->scope();
            $clients = [];

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $clients += $this->lock($scope->clients(ClientRecord::query())->whereIn('clients.id', $chunk));
            }

            foreach ($ids as $id) {
                if (! isset($clients[$id])) {
                    throw RecordNotFound::for('client', $id);
                }
            }

            return $this->apply($clients, $managerUserId);
        });
    }

    public function assignMatching(ClientQuery $query, ?int $managerUserId): int
    {
        $this->gate->authorize(self::PERMISSION);

        return $this->database->transaction(function () use ($query, $managerUserId): int {
            // The scope and filters of CarteraRepository: what the user would see for $query.
            $scope = PortfolioScope::forUser($this->auth->guard()->user(), $this->currentCompany, $query->companies);

            return $this->apply(
                $this->lock(ClientListing::filter($scope->clients(ClientRecord::query()), $query)),
                $managerUserId,
            );
        });
    }

    /**
     * Locks the rows of $clients and reads their company and manager, by id.
     *
     * @param  Builder<ClientRecord>  $clients
     * @return array<int, array{company: string, manager: int|null}>
     */
    private function lock(Builder $clients): array
    {
        $rows = $clients->orderBy('clients.id')->lockForUpdate()->toBase()
            ->get(['clients.id', 'clients.company', 'clients.manager_user_id']);
        $locked = [];

        foreach ($rows as $row) {
            $locked[(int) $row->id] = [
                'company' => (string) $row->company,
                'manager' => $row->manager_user_id === null ? null : (int) $row->manager_user_id,
            ];
        }

        return $locked;
    }

    /**
     * Sets $managerUserId on the $clients whose manager differs, with a log
     * entry and an audit entry each.
     *
     * @param  array<int, array{company: string, manager: int|null}>  $clients  By id, locked.
     *
     * @throws InvalidArgumentException When the manager may not manage every client.
     */
    private function apply(array $clients, ?int $managerUserId): int
    {
        if ($managerUserId !== null) {
            $this->guardManager($managerUserId, array_values(array_unique(array_column($clients, 'company'))));
        }

        $changes = array_filter($clients, fn (array $client): bool => $client['manager'] !== $managerUserId);

        if ($changes === []) {
            return 0;
        }

        foreach (array_chunk(array_keys($changes), self::CHUNK) as $chunk) {
            ClientRecord::query()->whereIn('id', $chunk)->update(['manager_user_id' => $managerUserId]);
        }

        $this->log($changes, $managerUserId);

        foreach ($changes as $clientId => $client) {
            $this->audit->record(
                'core.client.manager_changed',
                'client',
                $clientId,
                ['manager_user_id' => ['old' => $client['manager'], 'new' => $managerUserId]],
            );
        }

        return count($changes);
    }

    /**
     * One System entry per changed client, by the signed-in user, inserted
     * CHUNK rows per statement. A bulk insert skips the model, so the
     * metadata goes JSON-encoded and the timestamps are explicit.
     *
     * @param  array<int, array{company: string, manager: int|null}>  $changes  By client id.
     */
    private function log(array $changes, ?int $managerUserId): void
    {
        $names = User::withTrashed()
            ->whereIn('id', array_filter([$managerUserId, ...array_column($changes, 'manager')], fn (?int $id): bool => $id !== null))
            ->pluck('name', 'id')
            ->all();
        $authorId = $this->auth->guard()->id();
        $now = now();
        $entries = [];

        foreach ($changes as $clientId => $client) {
            $entries[] = [
                'company' => $client['company'],
                'client_id' => $clientId,
                'type' => ActivityType::System->value,
                'summary' => self::summary($client['manager'], $managerUserId, $names),
                'user_id' => $authorId,
                'occurred_at' => $now,
                'metadata' => json_encode(['from' => $client['manager'], 'to' => $managerUserId], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($entries, self::CHUNK) as $chunk) {
            ActivityRecord::query()->insert($chunk);
        }
    }

    /**
     * «Gestor asignado: …», «Gestor cambiado: de … a …» or «Gestor quitado: …»,
     * cut to the 255 characters of activities.summary.
     *
     * @param  array<int, string>  $names  By user id.
     */
    private static function summary(?int $from, ?int $to, array $names): string
    {
        $name = fn (int $id): string => $names[$id] ?? "usuario {$id}";

        $summary = match (true) {
            $from === null => 'Gestor asignado: '.$name((int) $to),
            $to === null => 'Gestor quitado: '.$name($from),
            default => 'Gestor cambiado: de '.$name($from).' a '.$name($to),
        };

        return mb_substr($summary, 0, 255);
    }

    /**
     * $managerUserId must be an assignee (assignees()) who works in each of
     * $companies.
     *
     * @param  list<string>  $companies  CompanyCode values.
     *
     * @throws InvalidArgumentException
     */
    private function guardManager(int $managerUserId, array $companies): void
    {
        $manager = $this->candidates()->whereKey($managerUserId)->first();
        $worksIn = $manager === null ? [] : $this->worksIn($manager, available: false);

        if ($manager === null
            || array_diff($companies, $worksIn) !== []
            || array_intersect($worksIn, $this->availableCodes()) === []) {
            throw new InvalidArgumentException("User {$managerUserId} is not a portfolio manager or analyst of the company of every client.");
        }
    }

    /**
     * Users with an assignee role, not soft-deleted, with their roles loaded.
     *
     * @return Builder<User>
     */
    private function candidates(): Builder
    {
        return User::query()
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', array_map(fn (Role $role): string => $role->value, self::ASSIGNEE_ROLES)))
            ->with('roles');
    }

    /**
     * The companies $user may work in (CompanyAccess: none when inactive),
     * limited with $available to those available to the signed-in user.
     *
     * @return list<string> CompanyCode values.
     */
    private function worksIn(User $user, bool $available = true): array
    {
        $codes = array_map(fn (CompanyCode $code): string => $code->value, $this->access->companiesFor($user));

        return $available ? array_values(array_intersect($codes, $this->availableCodes())) : $codes;
    }

    /**
     * @return list<string> CompanyCode values of CurrentCompany::available().
     */
    private function availableCodes(): array
    {
        return array_map(fn (Company $company): string => $company->code->value, $this->currentCompany->available());
    }
}
