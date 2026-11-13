<?php

namespace App\Modules\Cartera\Application\Clients;

use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the user asked of the client list (and of its Excel export and its
 * bulk manager assignment) through the query string. Values it does not
 * know are dropped, never an error: a stale bookmark still opens the list.
 *
 * URL names: buscar, estado, asesor, vencidos, sin_gestion, gestor, sort,
 * direction (the last two are the ones <x-ui.table> writes).
 */
final readonly class ClientListFilter
{
    public const string DEFAULT_SORT = 'overdue';

    public const string DEFAULT_DIRECTION = 'desc';

    private const int SEARCH_MAX_LENGTH = 100;

    /**
     * @param  ManagerFilter  $manager  The "Gestor" filter in force.
     * @param  ManagerFilter  $defaultManager  The one the list opens on for this user (ManagerFilter::defaultFor()).
     * @param  int|null  $userId  The user "Mi cartera" stands for; without one it filters nothing.
     */
    public function __construct(
        public string $search = '',
        public ?ClientStatus $status = null,
        public ?int $advisorCode = null,
        public bool $onlyOverdue = false,
        public bool $unmanaged = false,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        public ManagerFilter $manager = ManagerFilter::All,
        public ManagerFilter $defaultManager = ManagerFilter::All,
        public ?int $userId = null,
    ) {}

    /**
     * The filter of $user: "Mi cartera" is theirs, and the list opens on the
     * default of their permissions unless the URL says otherwise.
     *
     * @param  array<string, mixed>  $input  Usually Request::query().
     */
    public static function forUser(array $input, ?Authenticatable $user): self
    {
        $id = $user?->getAuthIdentifier();

        $default = ManagerFilter::defaultFor($user instanceof Authorizable ? $user : null);

        return self::fromInput($input, $default, is_numeric($id) ? (int) $id : null);
    }

    /**
     * @param  array<string, mixed>  $input  Usually Request::query().
     */
    public static function fromInput(array $input, ManagerFilter $defaultManager = ManagerFilter::All, ?int $userId = null): self
    {
        $search = is_string($input['buscar'] ?? null) ? mb_substr(trim($input['buscar']), 0, self::SEARCH_MAX_LENGTH) : '';
        $advisor = $input['asesor'] ?? null;
        $sort = $input['sort'] ?? null;
        $direction = $input['direction'] ?? null;
        $manager = $input['gestor'] ?? null;

        return new self(
            search: $search,
            status: is_string($input['estado'] ?? null) ? ClientStatus::tryFrom($input['estado']) : null,
            advisorCode: is_string($advisor) && ctype_digit($advisor) && strlen($advisor) <= 9 ? (int) $advisor : null,
            onlyOverdue: self::flag($input['vencidos'] ?? null),
            unmanaged: self::flag($input['sin_gestion'] ?? null),
            sort: is_string($sort) && in_array($sort, ClientQuery::SORTS, true) ? $sort : self::DEFAULT_SORT,
            direction: is_string($direction) && in_array($direction, ClientQuery::DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION,
            manager: (is_string($manager) ? ManagerFilter::tryFrom($manager) : null) ?? $defaultManager,
            defaultManager: $defaultManager,
            userId: $userId,
        );
    }

    /**
     * @param  int  $unmanagedDays  Days without a logged action that make an overdue client "unmanaged".
     */
    public function toQuery(CarbonImmutable $today, int $unmanagedDays): ClientQuery
    {
        return new ClientQuery(
            search: $this->search === '' ? null : $this->search,
            statuses: $this->status === null ? [] : [$this->status],
            advisorCode: $this->advisorCode,
            onlyOverdue: $this->onlyOverdue || $this->unmanaged,
            inactiveSince: $this->unmanaged ? $today->subDays($unmanagedDays) : null,
            sort: $this->sort,
            direction: $this->direction,
            managerUserId: $this->manager === ManagerFilter::Mine ? $this->userId : null,
            unassigned: $this->manager === ManagerFilter::Unassigned,
        );
    }

    /**
     * Whether any filter narrows the list (the order does not count).
     */
    public function isFiltered(): bool
    {
        return $this->search !== ''
            || $this->status !== null
            || $this->advisorCode !== null
            || $this->onlyOverdue
            || $this->unmanaged
            || $this->manager !== ManagerFilter::All;
    }

    /**
     * The query string that rebuilds this filter, without the defaults. The
     * manager filter is left out only when both it and the user's default are
     * the whole portfolio, so a link always means the same for its user.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $wholePortfolio = $this->manager === ManagerFilter::All && $this->defaultManager === ManagerFilter::All;

        return array_filter([
            'buscar' => $this->search,
            'estado' => $this->status?->value,
            'asesor' => $this->advisorCode === null ? null : (string) $this->advisorCode,
            'vencidos' => $this->onlyOverdue ? '1' : null,
            'sin_gestion' => $this->unmanaged ? '1' : null,
            'gestor' => $wholePortfolio ? null : $this->manager->value,
            'sort' => $this->sort === self::DEFAULT_SORT ? null : $this->sort,
            'direction' => $this->direction === self::DEFAULT_DIRECTION ? null : $this->direction,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The query string of the list without any filter: the whole portfolio,
     * named when the user's default is another.
     *
     * @return array<string, string>
     */
    public function clearQuery(): array
    {
        return $this->defaultManager === ManagerFilter::All ? [] : ['gestor' => ManagerFilter::All->value];
    }

    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true || $value === 'on';
    }
}
