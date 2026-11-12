<?php

namespace App\Modules\Core\Infrastructure\Ingest;

/**
 * One active row of a contactos batch (etl/esquema.py CONTACTOS), cleaned:
 * texts trimmed (blank is none), its valid e-mails in lowercase and the
 * phone and mobile in one field.
 */
final readonly class ContactRow
{
    /** Longest value the contacts columns hold. */
    private const int MAX_LENGTH = 255;

    /**
     * @param  list<string>  $emails  Valid, unique and in the order SAP has them.
     */
    private function __construct(
        public string $clientCode,
        public ?string $name,
        public ?string $position,
        public array $emails,
        public ?string $phone,
        public bool $isPrimary,
    ) {}

    /**
     * The row, or null when the contact is inactive in SAP ("activo" is not
     * Y) or has no client code.
     *
     * @param  array<string, scalar|null>  $row
     */
    public static function read(array $row): ?self
    {
        $code = self::text($row['codigo_sn'] ?? null);

        if ($code === null || ! self::isYes($row['activo'] ?? null)) {
            return null;
        }

        $phones = array_unique(array_filter([self::text($row['telefono'] ?? null), self::text($row['celular'] ?? null)]));

        return new self(
            clientCode: $code,
            name: self::text($row['nombre'] ?? null),
            position: self::text($row['cargo'] ?? null),
            emails: self::emails($row['correo'] ?? null),
            phone: $phones === [] ? null : mb_substr(implode(' / ', $phones), 0, self::MAX_LENGTH),
            isPrimary: self::isYes($row['principal'] ?? null),
        );
    }

    /**
     * The contacts of one client from its rows: one per e-mail, each e-mail
     * once. When rows share an e-mail, it goes to the primary contact, then
     * to a named one (a person rather than the general row), then to the
     * first. A row left without e-mails is kept only for its phone.
     *
     * @param  list<self>  $rows
     * @return list<array{name: string|null, email: string|null, phone: string|null, position: string|null, is_primary: bool}>
     */
    public static function resolve(array $rows): array
    {
        $owners = [];

        foreach ($rows as $index => $row) {
            foreach ($row->emails as $email) {
                if (! isset($owners[$email]) || $row->outranks($rows[$owners[$email]])) {
                    $owners[$email] = $index;
                }
            }
        }

        $contacts = [];

        foreach ($rows as $index => $row) {
            $emails = array_values(array_filter($row->emails, fn (string $email): bool => $owners[$email] === $index));

            if ($emails === [] && $row->phone !== null) {
                $emails = [null];
            }

            foreach ($emails as $email) {
                $contacts[] = [
                    'name' => $row->name,
                    'email' => $email,
                    'phone' => $row->phone,
                    'position' => $row->position,
                    'is_primary' => $row->isPrimary,
                ];
            }
        }

        return $contacts;
    }

    private function outranks(self $other): bool
    {
        return [$this->isPrimary, $this->name !== null] > [$other->isPrimary, $other->name !== null];
    }

    /**
     * The valid e-mails of a field that may hold several, separated by ";",
     * "," or blanks (Unicode ones too, such as a non-breaking space).
     *
     * @return list<string>
     */
    private static function emails(mixed $value): array
    {
        $emails = [];

        foreach (preg_split('/[;,\s\p{Z}]+/u', mb_strtolower((string) $value), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
            if (strlen($email) <= self::MAX_LENGTH && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }

    private static function isYes(mixed $value): bool
    {
        return strtoupper(trim((string) $value)) === 'Y';
    }
}
