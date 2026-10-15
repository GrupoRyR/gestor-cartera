<?php

namespace App\Modules\Core\Contracts\Exceptions;

use Throwable;

/**
 * IngestaSink::receive() rejected a batch. Nothing was stored except the
 * rejected entry in the load history.
 */
final class InvalidBatch extends ContractException
{
    /**
     * @param  string  $message  Summary of the failure, also stored as LoadRecord::$error.
     * @param  array<string, list<string>>  $errors  Messages per field, keyed like Laravel
     *                                               validation errors, e.g. "rows.3.saldo".
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Messages per field; empty when the failure is not tied to a field.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
