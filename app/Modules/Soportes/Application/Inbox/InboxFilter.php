<?php

namespace App\Modules\Soportes\Application\Inbox;

use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * What the user asked of the receipts inbox through the query string.
 * Values it does not know are dropped, never an error.
 *
 * URL names: estado (the tab, a ReceiptStatus value; pendiente by default),
 * buscar (a fragment of the client name or code, on the copies taken at
 * upload), desde and hasta (payment dates, Y-m-d, both inclusive).
 */
final readonly class InboxFilter
{
    /**
     * Escape character of the LIKE patterns, the same on MySQL and SQLite.
     */
    private const string ESCAPE = '!';

    public function __construct(
        public ReceiptStatus $status = ReceiptStatus::Pending,
        public ?string $search = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  Usually Request::query().
     */
    public static function fromInput(array $input): self
    {
        $status = $input['estado'] ?? null;
        $search = $input['buscar'] ?? null;
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';

        return new self(
            status: is_string($status) ? ReceiptStatus::tryFrom($status) ?? ReceiptStatus::Pending : ReceiptStatus::Pending,
            search: $search === '' ? null : $search,
            from: self::date($input['desde'] ?? null),
            to: self::date($input['hasta'] ?? null),
        );
    }

    /**
     * Narrows $query by the search and the payment dates; the tab is left
     * to the caller, so the tab counts share these filters.
     *
     * @param  Builder<Receipt>  $query
     * @return Builder<Receipt>
     */
    public function narrow(Builder $query): Builder
    {
        if ($this->search !== null) {
            $pattern = '%'.str_replace(
                [self::ESCAPE, '%', '_'],
                [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
                $this->search,
            ).'%';

            $query->where(fn (Builder $match) => $match
                ->whereRaw("client_name LIKE ? ESCAPE '".self::ESCAPE."'", [$pattern])
                ->orWhereRaw("client_code LIKE ? ESCAPE '".self::ESCAPE."'", [$pattern]));
        }

        if ($this->from !== null) {
            $query->whereDate('paid_at', '>=', $this->from->toDateString());
        }

        if ($this->to !== null) {
            $query->whereDate('paid_at', '<=', $this->to->toDateString());
        }

        return $query;
    }

    /**
     * Whether the search or the dates narrow the list (the tab does not count).
     */
    public function isFiltered(): bool
    {
        return $this->search !== null || $this->from !== null || $this->to !== null;
    }

    /**
     * The query string that rebuilds this filter, without the defaults.
     *
     * @param  ReceiptStatus|null  $status  Another tab, to link to it keeping the rest; null keeps this one.
     * @return array<string, string>
     */
    public function query(?ReceiptStatus $status = null): array
    {
        $status ??= $this->status;

        return array_filter([
            'estado' => $status === ReceiptStatus::Pending ? null : $status->value,
            'buscar' => $this->search,
            'desde' => $this->from?->toDateString(),
            'hasta' => $this->to?->toDateString(),
        ], fn (?string $value): bool => $value !== null);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidArgumentException) {
            return null;
        }

        // A date that does not exist, such as 2026-02-31, rolls over to another one.
        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }
}
