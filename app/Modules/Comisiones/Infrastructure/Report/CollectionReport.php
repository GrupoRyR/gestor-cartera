<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Domain\Calculation\CollectionDetail;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use Illuminate\Support\Str;

/**
 * The collection report of one seller code, or of a commission derived from
 * a code's collections (85.1), as §8.2 draws it: the payments and crossings
 * of the period in one table, ordered by payment date, with their totals.
 *
 * The total is computed from the lines the way the engine computes the
 * code's commission (the exact line commissions, rounded once to the peso),
 * and must equal the figure of the consolidated report (§9.3): matches()
 * says whether it does, and ReportArchive refuses to store a report that
 * does not. Each line is shown rounded to the peso; their shown sum may
 * differ from the total by a few pesos, the total never does.
 */
final readonly class CollectionReport
{
    /**
     * @param  string  $reference  The code ("14") or the derived key ("85.1").
     * @param  string  $seller  The «Comercial» of the subtitle: who is paid for this report.
     * @param  string  $lineSeller  The «Vendedor» column: the seller of the lines (code 85 for 85.1).
     * @param  int  $version  The settlement version the report comes from (§10).
     * @param  list<CollectionDetail>  $lines  As the settlement keeps them: by payment date, then key.
     * @param  Money  $expected  The consolidated figure: the code's collection commission, or the derived commission.
     * @param  bool  $showsDays  False for a flat derived commission: the days column shows «—».
     * @param  string|null  $note  The note of a derived report (§8.2), null for a code.
     */
    public function __construct(
        public string $reference,
        public string $seller,
        public string $lineSeller,
        public Period $period,
        public int $version,
        public array $lines,
        public Money $expected,
        public bool $showsDays,
        public ?string $note,
    ) {}

    /** The commission total printed on the PDF: the exact line commissions, rounded once. */
    public function total(): Money
    {
        return Money::sum(array_map(fn (CollectionDetail $line): Money => $line->commission, $this->lines))->rounded();
    }

    /** Sum of «Importe original». */
    public function invoiceTotal(): Money
    {
        return Money::sum(array_map(fn (CollectionDetail $line): Money => $line->invoiceAmount, $this->lines));
    }

    /** Sum of «Valor pagado», without VAT. */
    public function paidTotal(): Money
    {
        return Money::sum(array_map(fn (CollectionDetail $line): Money => $line->paidBase, $this->lines));
    }

    /** «Número de transacciones»: payments and crossings. */
    public function transactions(): int
    {
        return count($this->lines);
    }

    /** §9.3: the PDF total equals the consolidated figure exactly. */
    public function matches(): bool
    {
        return $this->total()->equals($this->expected);
    }

    /** PDF total minus the consolidated figure; zero when they match. */
    public function difference(): Money
    {
        return $this->total()->minus($this->expected);
    }

    /**
     * "Informe_Recaudo_<código>_<NOMBRE>.pdf" (§8.2): the name folded to
     * ASCII, with underscores for spaces and anything else a file name
     * should not carry.
     */
    public function fileName(): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($this->seller)), '_');

        return sprintf('Informe_Recaudo_%s_%s.pdf', $this->reference, $name === '' ? 'Comercial' : $name);
    }
}
