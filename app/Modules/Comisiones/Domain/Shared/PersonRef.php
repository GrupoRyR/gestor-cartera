<?php

namespace App\Modules\Comisiones\Domain\Shared;

use InvalidArgumentException;

/**
 * The person who gets paid (§4). One person can hold several seller codes
 * and roles (Harold Marín = 32 and 34); the person total is what payroll
 * receives (§5.7).
 */
final readonly class PersonRef
{
    /**
     * @param  string  $id  Stable key, e.g. "harold-marin".
     * @param  string  $name  Name shown in reports, e.g. "Harold Marín".
     *
     * @throws InvalidArgumentException when the id or the name is blank.
     */
    public function __construct(
        public string $id,
        public string $name,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A person needs an id.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException("Person {$id} needs a name.");
        }
    }
}
