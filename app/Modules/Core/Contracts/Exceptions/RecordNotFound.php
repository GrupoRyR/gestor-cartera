<?php

namespace App\Modules\Core\Contracts\Exceptions;

/**
 * A contract was given the id of a record that does not exist or that lies
 * outside the caller's scope. Both cases look the same on purpose, so the
 * existence of other users' records does not leak; HTTP callers answer 404.
 */
final class RecordNotFound extends ContractException
{
    /**
     * @param  string  $type  Kind of record in plain English, e.g. "client" or "commitment".
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string $id,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(string $type, int|string $id): self
    {
        return new self($type, $id, "No {$type} with id {$id} exists in the current scope.");
    }
}
