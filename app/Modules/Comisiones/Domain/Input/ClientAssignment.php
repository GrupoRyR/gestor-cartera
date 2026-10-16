<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Dates;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The seller assigned to a client from a date on (Report 5 history). A
 * crossing goes to the seller in force on its date (§5.4, §14.5).
 */
final readonly class ClientAssignment
{
    public DateTimeImmutable $effectiveFrom;

    /**
     * @throws InvalidArgumentException when the client code is blank.
     */
    public function __construct(
        public string $clientCode,
        public int $sellerCode,
        DateTimeImmutable $effectiveFrom,
    ) {
        if (trim($clientCode) === '') {
            throw new InvalidArgumentException('A client assignment needs a client code.');
        }

        $this->effectiveFrom = Dates::day($effectiveFrom);
    }
}
