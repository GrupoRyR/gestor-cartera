<?php

namespace App\Modules\Core\Console\Migracion;

use App\Models\User;

/**
 * Finds the app user who wrote a legacy row, by e-mail: Fase 1 user ids go
 * through fase1_users.csv; Supabase rows already carry the e-mail. Only
 * users that are not deleted count. An author the app does not have comes
 * back as LegacyAuthor::unknown(); each e-mail is looked up once.
 */
final class LegacyAuthors
{
    /**
     * @var array<int, string> Fase 1 users.id => normalized e-mail.
     */
    private array $fase1Emails = [];

    /**
     * @var array<string, int|null> Normalized e-mail => app user id, or null when there is none.
     */
    private array $users = [];

    private function __construct() {}

    /**
     * Reads fase1_users.csv when present (a row it cannot read is left out,
     * with a warning on $report) and the app users with those e-mails.
     */
    public static function load(?CsvFile $fase1Users, ImportReport $report): self
    {
        $authors = new self;

        $report->lookup($fase1Users, function (CsvRow $row) use ($authors): void {
            $authors->fase1Emails[$row->int('id')] = $row->email('email');
        });

        $emails = array_values(array_unique($authors->fase1Emails));

        foreach (array_chunk($emails, 500) as $chunk) {
            foreach (User::query()->whereIn('email', $chunk)->pluck('id', 'email') as $email => $id) {
                $authors->users[(string) $email] = (int) $id;
            }
        }

        foreach ($emails as $email) {
            $authors->users[$email] ??= null;
        }

        return $authors;
    }

    /**
     * The author of a Fase 1 row by its user_id; no id means the system.
     */
    public function fase1(?int $legacyUserId): LegacyAuthor
    {
        if ($legacyUserId === null) {
            return LegacyAuthor::system();
        }

        $email = $this->fase1Emails[$legacyUserId] ?? null;

        return $email === null ? LegacyAuthor::unknown("usuario Fase 1 #{$legacyUserId}") : $this->email($email);
    }

    /**
     * The author with e-mail $email, as written in the legacy row.
     */
    public function email(string $email): LegacyAuthor
    {
        $email = User::normalizeEmail($email);

        if (! array_key_exists($email, $this->users)) {
            $id = User::query()->where('email', $email)->value('id');
            $this->users[$email] = $id === null ? null : (int) $id;
        }

        $id = $this->users[$email];

        return $id === null ? LegacyAuthor::unknown($email) : LegacyAuthor::user($id);
    }
}
