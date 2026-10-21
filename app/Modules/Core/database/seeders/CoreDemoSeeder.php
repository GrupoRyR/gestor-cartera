<?php

namespace App\Modules\Core\Database\Seeders;

use App\Models\User;
use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Fictitious users and portfolio for local development: one demo user per
 * role (plus a commissions approver), the SAP advisors and sales team, and
 * per company about thirty clients with contacts, a year of monthly
 * receivables snapshots, activities and commitments. The latest snapshot
 * gives every client status. DatabaseSeeder runs it only with APP_ENV=local;
 * it does nothing when the demo users exist already.
 *
 * Values are derived from each client's position, not random, so every run
 * shows the same spread of statuses and ages.
 */
final class CoreDemoSeeder extends Seeder
{
    public const string EMAIL_DOMAIN = 'ryr.test';

    /**
     * The commissions approver role belongs to the Comisiones module, which
     * Core may not import, so it is named by its value here.
     */
    public const string COMMISSIONS_APPROVER_ROLE = 'comisiones_aprobador';

    private const string TIMEZONE = 'America/Bogota';

    private const int CLIENTS_PER_COMPANY = 28;

    private const int MONTHS_OF_HISTORY = 12;

    /**
     * Demo users: e-mail slug => [name, roles].
     *
     * @var array<string, array{string, list<string>}>
     */
    private const array USERS = [
        'admin' => ['Administrador (demo)', ['admin']],
        'gerente-cartera' => ['Gerente de cartera (demo)', ['gerente_cartera']],
        'analista-cartera' => ['Analista de cartera (demo)', ['analista_cartera']],
        'asesor' => ['Asesor comercial (demo)', ['asesor']],
        'gerente-comercial' => ['Gerente comercial (demo)', ['gerente_comercial']],
        'tesoreria' => ['Tesorería (demo)', ['tesoreria']],
        // A second portfolio manager who approves what the first one calculates.
        'aprobador-comisiones' => ['Aprobador de comisiones (demo)', ['gerente_cartera', self::COMMISSIONS_APPROVER_ROLE]],
    ];

    /**
     * SAP sales employees per company: code => name. The Segurex codes are
     * those of the commission parameters, so Comisiones finds the same people.
     *
     * @var array<string, array<int, string>>
     */
    private const array ADVISORS = [
        'SGX' => [14 => 'Mónica Rivera', 15 => 'Paola Vargas', 16 => 'Carolina Ramírez', 32 => 'Harold Marín', 41 => 'Jenny Rocío Buitrago'],
        'SDC' => [201 => 'Felipe Ospina', 202 => 'Juliana Duque', 203 => 'Santiago Vélez', 204 => 'Natalia Arango', 205 => 'Mauricio Salazar'],
    ];

    /**
     * The status each client should end up with, in order; it repeats to
     * cover CLIENTS_PER_COMPANY and spreads over the advisors by position.
     *
     * @var list<ClientStatus>
     */
    private const array STATUS_PLAN = [
        ClientStatus::Overdue, ClientStatus::Current, ClientStatus::DueSoon, ClientStatus::InProgress,
        ClientStatus::Committed, ClientStatus::Overdue, ClientStatus::Current, ClientStatus::Defaulted,
        ClientStatus::PaymentReceived, ClientStatus::Overdue, ClientStatus::DueSoon, ClientStatus::Legal,
        ClientStatus::Current, ClientStatus::Committed,
    ];

    /** Days overdue of the oldest invoice of an overdue client, by position. */
    private const array OVERDUE_AGES = [22, 38, 47, 66, 85, 96, 130, 210];

    /** Invoices written so far: keeps document numbers apart. */
    private int $invoices = 0;

    public function run(RecomputeClientStatuses $statuses): void
    {
        if (User::query()->where('email', self::email('admin'))->exists()) {
            return;
        }

        $users = $this->users();
        $today = CarbonImmutable::today(self::TIMEZONE);

        foreach (CompanyCode::cases() as $company) {
            $advisors = $this->advisors($company, $users);
            $clients = $this->clients($company, $advisors, $users);

            $this->history($company, $clients, $today);
            $latest = $this->latestSnapshot($company, $clients, $today);

            // Every client counts as new: the demo starts without status-change entries.
            $statuses->forCompany($company, $latest->id, array_map(fn (ClientRecord $client): int => $client->id, $clients));

            $this->activities($company, $clients, $users, $today);
            $this->commitments($company, $clients, $users, $today);
        }
    }

    public static function email(string $slug): string
    {
        return "demo.{$slug}@".self::EMAIL_DOMAIN;
    }

    /**
     * @return array<string, User>
     */
    private function users(): array
    {
        $users = [];

        foreach (self::USERS as $slug => [$name, $roles]) {
            $user = User::withTrashed()->restoreOrCreate(
                ['email' => User::normalizeEmail(self::email($slug))],
                ['name' => $name, 'is_active' => true],
            );

            foreach ($roles as $role) {
                $user->assignRole(SpatieRole::findOrCreate($role, 'web'));
            }

            CompanyRecord::assignedTo($user)->syncWithoutDetaching(array_map(
                fn (CompanyCode $code): string => $code->value,
                CompanyCode::cases(),
            ));

            $users[$slug] = $user;
        }

        return $users;
    }

    /**
     * The company's advisors; the first one is the demo advisor's, and the
     * sales manager's team has the first three.
     *
     * @param  array<string, User>  $users
     * @return list<AdvisorRecord>
     */
    private function advisors(CompanyCode $company, array $users): array
    {
        $advisors = [];

        foreach (self::ADVISORS[$company->value] as $code => $name) {
            $linked = $advisors === [];

            $factory = $linked ? AdvisorRecord::factory()->linkedTo($users['asesor']) : AdvisorRecord::factory();

            $advisors[] = $factory->create([
                'company' => $company,
                'code' => $code,
                'name' => $name,
                'email' => $linked ? $users['asesor']->email : null,
            ]);
        }

        AdvisorRecord::teamOf($users['gerente-comercial'])->attach(
            array_map(fn (AdvisorRecord $advisor): int => $advisor->id, array_slice($advisors, 0, 3)),
        );

        return $advisors;
    }

    /**
     * Clients spread over the advisors, each with a primary contact (a few
     * without e-mail) and, for some, a second contact. The status set here
     * is the starting one: the latest snapshot settles it.
     *
     * @param  list<AdvisorRecord>  $advisors
     * @param  array<string, User>  $users
     * @return list<ClientRecord>
     */
    private function clients(CompanyCode $company, array $advisors, array $users): array
    {
        $clients = [];

        for ($i = 0; $i < self::CLIENTS_PER_COMPANY; $i++) {
            $planned = self::plannedStatus($i);

            $client = ClientRecord::factory()->create([
                'company' => $company,
                'advisor_code' => $advisors[$i % count($advisors)]->code,
                'manager_user_id' => $i % 3 === 0 ? $users['analista-cartera']->id : null,
                // Manual statuses survive the snapshot; a client that paid
                // everything was overdue before it.
                'status' => match (true) {
                    in_array($planned, [ClientStatus::InProgress, ClientStatus::Committed, ClientStatus::Defaulted, ClientStatus::Legal], true) => $planned,
                    $planned === ClientStatus::PaymentReceived => ClientStatus::Overdue,
                    default => ClientStatus::Current,
                },
            ]);

            ContactRecord::factory()->primary()->create([
                'client_id' => $client->id,
                ...($i % 9 === 4 ? ['email' => null] : []),
            ]);

            if ($i % 4 === 0) {
                ContactRecord::factory()->create(['client_id' => $client->id]);
            }

            $clients[] = $client;
        }

        return $clients;
    }

    /**
     * One snapshot at the end of each of the last months, so the charts
     * have a series. Balances grow slowly towards today.
     *
     * @param  list<ClientRecord>  $clients
     */
    private function history(CompanyCode $company, array $clients, CarbonImmutable $today): void
    {
        for ($back = self::MONTHS_OF_HISTORY - 1; $back >= 1; $back--) {
            $cutoff = $today->startOfMonth()->subMonths($back)->endOfMonth()->startOfDay();
            $growth = 1 - $back * 0.03;
            $rows = [];

            foreach ($clients as $i => $client) {
                $count = 1 + ($i + $back) % 3;

                for ($n = 0; $n < $count; $n++) {
                    $days = [-25, -3, 12, 34, 58, 75, 105][($i * 3 + $n + $back) % 7];
                    $rows[] = $this->invoiceRow($company, $client, $cutoff, $days, self::amount($i, $n) * $growth, "H{$back}");
                }
            }

            $this->snapshot($company, $cutoff, $rows);
        }
    }

    /**
     * Today's snapshot: each client's invoices match its planned status.
     *
     * @param  list<ClientRecord>  $clients
     */
    private function latestSnapshot(CompanyCode $company, array $clients, CarbonImmutable $today): PortfolioLoadRecord
    {
        $rows = [];

        foreach ($clients as $i => $client) {
            $oldest = self::OVERDUE_AGES[$i % count(self::OVERDUE_AGES)];

            $ages = match (self::plannedStatus($i)) {
                ClientStatus::PaymentReceived => [],
                ClientStatus::Current => $i % 2 === 0 ? [-35, -18] : [-24],
                ClientStatus::DueSoon => [-($i % 4), -30],
                ClientStatus::Legal => [240, 190],
                default => [$oldest, (int) round($oldest / 2), -20],
            };

            foreach ($ages as $n => $days) {
                $rows[] = $this->invoiceRow($company, $client, $today, $days, self::amount($i, $n), 'A');
            }
        }

        return $this->snapshot($company, $today, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $invoices  Rows without portfolio_load_id.
     */
    private function snapshot(CompanyCode $company, CarbonImmutable $cutoff, array $invoices): PortfolioLoadRecord
    {
        $total = array_sum(array_map(fn (array $row): float => (float) $row['balance'], $invoices));
        $overdue = array_sum(array_map(fn (array $row): float => $row['days_overdue'] > 0 ? (float) $row['balance'] : 0.0, $invoices));

        $snapshot = PortfolioLoadRecord::query()->create([
            'company' => $company,
            'cutoff_date' => $cutoff->toDateString(),
            'load_id' => null,
            'source' => LoadSource::Robot,
            'invoice_count' => count($invoices),
            'total' => sprintf('%.2f', $total),
            'overdue' => sprintf('%.2f', $overdue),
        ]);

        foreach (array_chunk($invoices, 500) as $chunk) {
            InvoiceRecord::query()->insert(array_map(fn (array $row): array => [...$row, 'portfolio_load_id' => $snapshot->id], $chunk));
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(CompanyCode $company, ClientRecord $client, CarbonImmutable $cutoff, int $daysOverdue, float $amount, string $series): array
    {
        $dueAt = $cutoff->subDays($daysOverdue);
        $amount = sprintf('%.2f', round($amount, -3));
        $number = ++$this->invoices;

        return [
            'company' => $company->value,
            'client_id' => $client->id,
            'document_number' => $series.str_pad((string) (100_000 + $number), 7, '0', STR_PAD_LEFT),
            'document_type' => '13',
            'installment' => null,
            'issued_at' => $dueAt->subDays(30)->toDateString(),
            'due_at' => $dueAt->toDateString(),
            'amount' => $amount,
            'balance' => $amount,
            'days_overdue' => $daysOverdue,
            'advisor_code' => $client->advisor_code,
        ];
    }

    /**
     * Calls, notes, e-mails and a discount by different users over the last
     * weeks, on two of every three clients.
     *
     * @param  list<ClientRecord>  $clients
     * @param  array<string, User>  $users
     */
    private function activities(CompanyCode $company, array $clients, array $users, CarbonImmutable $today): void
    {
        $entries = [
            [ActivityType::Call, 'Llamada a tesorería', 'Contestó; pagará la próxima semana', 'analista-cartera'],
            [ActivityType::Note, 'Revisión del estado de cuenta', null, 'gerente-cartera'],
            [ActivityType::EmailSent, 'Envío del estado de cuenta', null, 'analista-cartera'],
            [ActivityType::Call, 'Seguimiento al pago', 'No contestó', 'asesor'],
            [ActivityType::EmailReceived, 'Respuesta del cliente', null, null],
            [ActivityType::DiscountOffered, 'Descuento por pronto pago del 2 %', null, 'gerente-comercial'],
        ];

        foreach ($clients as $i => $client) {
            if ($i % 3 === 2) {
                continue;
            }

            $latest = null;

            for ($n = 0; $n <= $i % 4; $n++) {
                [$type, $summary, $result, $author] = $entries[($i + $n) % count($entries)];

                // The demo advisor only works their own clients.
                if ($author === 'asesor' && $i % count(self::ADVISORS[$company->value]) !== 0) {
                    $author = 'analista-cartera';
                }

                $at = $today->subDays(1 + $n * 6 + $i % 5)->setTime(9 + $n, 15);
                $latest = $latest === null || $at->greaterThan($latest) ? $at : $latest;

                ActivityRecord::query()->create([
                    'company' => $company,
                    'client_id' => $client->id,
                    'type' => $type,
                    'summary' => $summary,
                    'details' => null,
                    'result' => $result,
                    'user_id' => $author === null ? null : $users[$author]->id,
                    'occurred_at' => $at,
                    'metadata' => null,
                ]);
            }

            $client->update(['last_activity_at' => $latest]);
        }
    }

    /**
     * Committed clients have a pending promise (some due this week, some
     * already past due), defaulted ones a broken one; a few clients carry
     * fulfilled and cancelled ones. The demo advisor writes those of their
     * own clients.
     *
     * @param  list<ClientRecord>  $clients
     * @param  array<string, User>  $users
     */
    private function commitments(CompanyCode $company, array $clients, array $users, CarbonImmutable $today): void
    {
        foreach ($clients as $i => $client) {
            $author = $i % count(self::ADVISORS[$company->value]) === 0 ? $users['asesor'] : $users['analista-cartera'];
            $amount = sprintf('%d.00', (5 + $i % 7) * 500_000);

            $attributes = match (true) {
                self::plannedStatus($i) === ClientStatus::Committed => [
                    'status' => CommitmentStatus::Pending,
                    'due_date' => $i % 2 === 0 ? $today->addDays(1 + $i % 5) : $today->subDays(2),
                ],
                self::plannedStatus($i) === ClientStatus::Defaulted => [
                    'status' => CommitmentStatus::Broken,
                    'due_date' => $today->subDays(9),
                    'broken_at' => $today->subDays(8),
                ],
                self::plannedStatus($i) === ClientStatus::PaymentReceived => [
                    'status' => CommitmentStatus::Fulfilled,
                    'due_date' => $today->subDays(6),
                    'fulfilled_at' => $today->subDays(6)->setTime(16, 0),
                ],
                $i % 10 === 3 => [
                    'status' => CommitmentStatus::Cancelled,
                    'due_date' => $today->addDays(10),
                    'cancelled_at' => $today->subDays(3),
                    'cancellation_reason' => 'El cliente pidió otra fecha.',
                ],
                $i % 10 === 6 => [
                    'status' => CommitmentStatus::Pending,
                    'due_date' => $today->addDays(4),
                ],
                // Clients of the demo advisor: promises they wrote themselves.
                $i % 10 === 0 => [
                    'status' => CommitmentStatus::Pending,
                    'due_date' => $i === 10 ? $today->subDays(3) : $today->addDays(2),
                ],
                default => null,
            };

            if ($attributes === null) {
                continue;
            }

            CommitmentRecord::query()->create([
                'company' => $company,
                'client_id' => $client->id,
                'advisor_code' => $client->advisor_code,
                'amount' => $amount,
                'notes' => 'Acordado por teléfono con tesorería.',
                'created_by' => $author->id,
                ...$attributes,
            ]);
        }
    }

    private static function plannedStatus(int $position): ClientStatus
    {
        return self::STATUS_PLAN[$position % count(self::STATUS_PLAN)];
    }

    /**
     * A round amount between about one and forty million pesos.
     */
    private static function amount(int $client, int $invoice): float
    {
        return (float) ((1 + ($client * 7 + $invoice * 13) % 40) * 950_000);
    }
}
