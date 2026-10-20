<?php

namespace App\Modules\Cobro\Domain\Copies;

/**
 * Who a company copies (Cc) on the weekly collection mail: fixed
 * addresses, the client's advisor and the sales managers of the client's
 * SAP group (CopyRecipients::resolve()).
 */
final readonly class CopySettings
{
    /**
     * @param  list<string>  $fixed  Addresses copied on every mail.
     * @param  bool  $advisor  Whether the client's advisor (OSLP.Email) is copied.
     * @param  list<array{group: string, emails: list<string>}>  $managers  Managers copied when the
     *                                                                      client's SAP group is "group".
     */
    public function __construct(
        public array $fixed = [],
        public bool $advisor = true,
        public array $managers = [],
    ) {}

    /**
     * Settings from their configured shape (fixed, advisor,
     * managers); anything malformed is left out.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $managers = [];

        foreach ((array) ($data['managers'] ?? []) as $manager) {
            $group = is_array($manager) ? trim((string) ($manager['group'] ?? '')) : '';
            $emails = is_array($manager) ? CopyRecipients::unique(array_map('strval', (array) ($manager['emails'] ?? []))) : [];

            if ($group !== '' && $emails !== []) {
                $managers[] = ['group' => $group, 'emails' => $emails];
            }
        }

        return new self(
            fixed: CopyRecipients::unique(array_map('strval', (array) ($data['fixed'] ?? []))),
            advisor: (bool) ($data['advisor'] ?? true),
            managers: $managers,
        );
    }
}
