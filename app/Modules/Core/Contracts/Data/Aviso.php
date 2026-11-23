<?php

namespace App\Modules\Core\Contracts\Data;

use InvalidArgumentException;

/**
 * Input of AvisoService::send(): one notice for one or more users.
 */
final readonly class Aviso
{
    /**
     * Ids of the users who receive it: distinct, in the order given.
     *
     * @var list<int>
     */
    public array $recipients;

    /**
     * @param  array<array-key, mixed>  $recipients  User ids; at least one, all positive integers. Repeats are dropped.
     * @param  string  $kind  What the notice is about, prefixed with the module key, e.g. cartera.compromiso; up to 40 characters.
     *                        It selects the link resolvers (AvisoService::linkUsing()).
     * @param  string  $title  The line shown in the bell; up to 160 characters.
     * @param  string|null  $body  Optional longer text.
     * @param  string|null  $company  CompanyCode value (SGX, SDC) when the notice belongs to one company.
     * @param  string|null  $subjectType  What it points at, e.g. commitment; up to 20 characters.
     * @param  int|null  $subjectId  The platform id of that subject.
     * @param  string|null  $key  Groups the notices that AvisoService::resolve() closes together,
     *                            e.g. commitment:12:partial; up to 80 characters.
     * @param  array<string, mixed>  $data  Extra JSON-serializable values for the link resolvers and the view.
     *
     * @throws InvalidArgumentException When a value is missing, blank or too long.
     */
    public function __construct(
        array $recipients,
        public string $kind,
        public string $title,
        public ?string $body = null,
        public ?string $company = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $key = null,
        public array $data = [],
    ) {
        $distinct = [];

        foreach ($recipients as $recipient) {
            if (! is_int($recipient) || $recipient < 1) {
                throw new InvalidArgumentException('Every notice recipient must be a user id.');
            }

            $distinct[$recipient] = $recipient;
        }

        if ($distinct === []) {
            throw new InvalidArgumentException('A notice needs at least one recipient.');
        }

        $this->recipients = array_values($distinct);

        self::text('kind', $kind, 40);
        self::text('title', $title, 160);

        if ($company !== null && preg_match('/^[A-Z]{3}$/', $company) !== 1) {
            throw new InvalidArgumentException('The notice company must be a company code such as SGX.');
        }

        if ($subjectType !== null) {
            self::text('subject type', $subjectType, 20);
        }

        if ($key !== null) {
            self::text('key', $key, 80);
        }
    }

    private static function text(string $field, string $value, int $max): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("The notice {$field} cannot be blank.");
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("The notice {$field} cannot be longer than {$max} characters.");
        }
    }
}
