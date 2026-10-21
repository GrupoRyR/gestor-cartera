<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The filters of the audit screen (§7.9), read from the query string:
 * usuario (a user id, or "sistema" for the events without one), desde and
 * hasta (days, both included, in the application timezone, Bogotá), evento
 * (an event name) and periodo (YYYY-MM). A value that does not parse is
 * ignored, so a stale link still opens the trail.
 */
final readonly class AuditFilters
{
    public const string SYSTEM = 'sistema';

    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        public ?string $user,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public ?string $event,
        public ?string $period,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $user = $request->query('usuario');
        $event = $request->query('evento');
        $period = $request->query('periodo');

        return new self(
            user: is_string($user) && ($user === self::SYSTEM || ctype_digit($user)) ? $user : null,
            from: self::day($request->query('desde'))?->startOfDay(),
            to: self::day($request->query('hasta'))?->endOfDay(),
            event: is_string($event) && preg_match('/^[a-z][a-z0-9_.]{2,99}$/', $event) === 1 ? $event : null,
            period: is_string($period) && preg_match('/^'.PeriodRoute::PATTERN.'$/', $period) === 1 ? $period : null,
        );
    }

    /**
     * @param  Builder<AuditRecord>  $query
     * @return Builder<AuditRecord>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->user === self::SYSTEM, fn (Builder $query) => $query->whereNull('user_id'))
            ->when($this->user !== null && $this->user !== self::SYSTEM, fn (Builder $query) => $query->where('user_id', (int) $this->user))
            ->when($this->from !== null, fn (Builder $query) => $query->where('created_at', '>=', $this->from))
            ->when($this->to !== null, fn (Builder $query) => $query->where('created_at', '<=', $this->to))
            ->when($this->event !== null, fn (Builder $query) => $query->where('evento', $this->event))
            ->when($this->period !== null, fn (Builder $query) => $query->where('periodo', $this->period));
    }

    public function isEmpty(): bool
    {
        return $this->user === null && $this->from === null && $this->to === null && $this->event === null && $this->period === null;
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::DAY, $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.timezone'));
        } catch (InvalidArgumentException) {
            return null;
        }

        // 2026-02-31 rolls over to March: not a day.
        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }
}
