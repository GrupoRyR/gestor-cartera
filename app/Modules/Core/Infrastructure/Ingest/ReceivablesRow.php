<?php

namespace App\Modules\Core\Infrastructure\Ingest;

use App\Modules\Core\Application\Portfolio\Cents;
use App\Modules\Core\Domain\Status\OpenItem;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * One row of a cartera batch (see IngestaSink), read into the columns of
 * "clients" and "invoices". Text is trimmed and blank text is no value;
 * amounts stay exact (integer cents, written back as decimal strings).
 * Dates stay CarbonImmutable at midnight, so the query builder stores them
 * the way Eloquent does.
 */
final readonly class ReceivablesRow
{
    /**
     * @param  array{code: string, name: string, tax_id: string, group: string|null, city: string|null, payment_terms: string|null, credit_limit: string|null, advisor_code: int|null}  $client
     * @param  array{document_number: string, document_type: string, installment: int|null, issued_at: CarbonImmutable, due_at: CarbonImmutable, amount: string, balance: string, days_overdue: int, advisor_code: int|null}  $invoice
     */
    private function __construct(
        public array $client,
        public array $invoice,
        public int $balanceCents,
        public int $daysOverdue,
    ) {}

    /**
     * @param  array<string, scalar|null>  $row
     * @param  CarbonImmutable  $cutoff  The snapshot cutoff date.
     *
     * @throws UnexpectedValueException When the row has no client code.
     * @throws InvalidArgumentException When an amount or a date is malformed.
     */
    public static function read(array $row, CarbonImmutable $cutoff): self
    {
        $cutoff = $cutoff->startOfDay();
        $code = self::text($row, 'codigo_sn') ?? throw new UnexpectedValueException('A cartera row has no codigo_sn.');
        $balance = self::cents($row, 'saldo') ?? 0;
        // Without a due date, "dias" (days past due on the cutoff) gives it back.
        $dueAt = self::date($row, 'fecha_vencimiento') ?? $cutoff->subDays(self::integer($row, 'dias') ?? 0);
        $issuedAt = self::date($row, 'fecha_documento') ?? self::date($row, 'fecha_contabilizacion') ?? $dueAt;
        $daysOverdue = OpenItem::fromDates(Cents::toFloat($balance), $dueAt, $cutoff)->daysOverdue;
        $advisorCode = self::integer($row, 'slp_code');
        // SAP uses -1 for "no sales employee".
        $advisorCode = $advisorCode !== null && $advisorCode >= 0 ? $advisorCode : null;
        $creditLimit = self::cents($row, 'limite_credito');

        return new self(
            client: [
                'code' => $code,
                'name' => self::text($row, 'nombre_sn') ?? $code,
                'tax_id' => self::text($row, 'nit') ?? '',
                'group' => self::text($row, 'grupo'),
                'city' => self::text($row, 'municipio'),
                'payment_terms' => self::text($row, 'condicion_pago'),
                'credit_limit' => $creditLimit === null ? null : Cents::toDecimal($creditLimit),
                'advisor_code' => $advisorCode,
            ],
            invoice: [
                'document_number' => self::text($row, 'documento') ?? '',
                'document_type' => self::text($row, 'tipo_doc') ?? '',
                'installment' => self::integer($row, 'no_plazo'),
                'issued_at' => $issuedAt,
                'due_at' => $dueAt,
                'amount' => Cents::toDecimal(self::cents($row, 'importe_original') ?? 0),
                'balance' => Cents::toDecimal($balance),
                'days_overdue' => $daysOverdue,
                'advisor_code' => $advisorCode,
            ],
            balanceCents: $balance,
            daysOverdue: $daysOverdue,
        );
    }

    public function clientCode(): string
    {
        return $this->client['code'];
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    private static function text(array $row, string $column): ?string
    {
        $value = trim((string) ($row[$column] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    private static function integer(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    private static function cents(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        if (is_bool($value)) {
            throw new InvalidArgumentException("Column [{$column}] of a cartera row is not an amount.");
        }

        return $value === null ? null : Cents::fromDecimal($value);
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    private static function date(array $row, string $column): ?CarbonImmutable
    {
        $value = self::text($row, $column);

        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value)
            ?? throw new InvalidArgumentException("Column [{$column}] of a cartera row is not a Y-m-d date.");
    }
}
