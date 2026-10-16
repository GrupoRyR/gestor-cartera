<?php

namespace App\Modules\Asesores\Domain\Client;

use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\Invoice;

/**
 * What a client owes today, from its open invoices: the balance, how much of
 * it is overdue, how old it is and where it stands against the credit limit.
 *
 * Balances are signed: a credit note or an advance lowers the figure of the
 * bucket it falls in, as it does in Core's totals.
 */
final readonly class Position
{
    public function __construct(
        public float $balance,
        public float $overdue,
        public float $notDue,
        public AgingBuckets $aging,
        public ?float $creditLimit,
    ) {}

    /**
     * @param  list<Invoice>  $invoices  The open invoices of the client.
     */
    public static function of(array $invoices, ?float $creditLimit = null): self
    {
        $cents = ['not_due' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0];

        // Whole cents, so thirty invoices of 0,10 add up to exactly 3,00.
        foreach ($invoices as $invoice) {
            $cents[InvoiceAging::key($invoice->daysOverdue)] += (int) round($invoice->balance * 100);
        }

        $overdue = $cents['days_1_30'] + $cents['days_31_60'] + $cents['days_61_90'] + $cents['over_90'];

        return new self(
            balance: ($overdue + $cents['not_due']) / 100,
            overdue: $overdue / 100,
            notDue: $cents['not_due'] / 100,
            aging: new AgingBuckets(
                notDue: $cents['not_due'] / 100,
                days1To30: $cents['days_1_30'] / 100,
                days31To60: $cents['days_31_60'] / 100,
                days61To90: $cents['days_61_90'] / 100,
                over90: $cents['over_90'] / 100,
            ),
            creditLimit: $creditLimit,
        );
    }

    /**
     * Whether the client has a credit limit assigned (zero means none).
     */
    public function hasCredit(): bool
    {
        return $this->limit() > 0;
    }

    /**
     * What is left of the limit; 0 without a limit or once it is used up.
     */
    public function available(): float
    {
        return $this->hasCredit() ? max(0.0, $this->limit() - $this->balance) : 0.0;
    }

    /**
     * By how much the balance passes the limit; 0 without a limit.
     */
    public function exceeded(): float
    {
        return $this->hasCredit() ? max(0.0, $this->balance - $this->limit()) : 0.0;
    }

    private function limit(): float
    {
        return max(0.0, $this->creditLimit ?? 0.0);
    }
}
