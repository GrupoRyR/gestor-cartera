<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use InvalidArgumentException;

/**
 * Something the settlement found (§9.6). Its key is stable between
 * recalculations, so a justification given for it keeps applying (§9.7).
 */
final readonly class Alert
{
    /**
     * @param  string  $reference  What the alert is about: a code ("99"), a line key or a SAP text.
     * @param  string  $message  Spanish, informal, for the analyst.
     * @param  int|null  $sellerCode  The code involved, when there is one.
     *
     * @throws InvalidArgumentException when the reference or the message is blank.
     */
    public function __construct(
        public AlertKind $kind,
        public string $reference,
        public string $message,
        public ?int $sellerCode = null,
    ) {
        if (trim($reference) === '') {
            throw new InvalidArgumentException("A {$kind->value} alert needs a reference.");
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException("The {$kind->value} alert of {$reference} needs a message.");
        }
    }

    /** "{kind}:{reference}", e.g. "codigo_desconocido:99"; unique within a result. */
    public function key(): string
    {
        return "{$this->kind->value}:{$this->reference}";
    }

    public function severity(): AlertSeverity
    {
        return $this->kind->severity();
    }

    public function isBlocking(): bool
    {
        return $this->severity() === AlertSeverity::Blocking;
    }
}
