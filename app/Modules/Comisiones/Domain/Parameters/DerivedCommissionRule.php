<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;
use InvalidArgumentException;

/**
 * One version of a commission derived from another code's results (§5.5):
 * 81.1 pays 0.1 % of code 81's sales and 85.1 pays 1 % of code 85's
 * collections. Each one is its own row in the consolidated report.
 * Collection-based derived commissions are flat: no tranches.
 */
final readonly class DerivedCommissionRule
{
    /**
     * @param  string  $key  Row key shown in reports, e.g. "81.1". Never a plain number: it is
     *                       the reference of the row's alerts and person role, next to the
     *                       seller codes ("81"), so it must not read as one.
     * @param  string  $label  Row label, e.g. "Sergio Caballero (venta)".
     *
     * @throws InvalidArgumentException when the key is blank or a plain number, or the label is blank.
     */
    public function __construct(
        public Validity $validity,
        public string $key,
        public string $label,
        public PersonRef $person,
        public int $sourceCode,
        public DerivedBase $base,
        public Percentage $rate,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A derived commission needs a key.');
        }

        if (ctype_digit(trim($key))) {
            throw new InvalidArgumentException("Derived commission key {$key} is a plain number; it would read as a seller code.");
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException("Derived commission {$key} needs a label.");
        }
    }
}
