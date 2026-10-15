<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\Contact;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use Carbon\CarbonImmutable;

/**
 * Builders of contract data with sensible Colombian sample values.
 *
 * Every argument is optional and named, so a test only states what matters:
 * Make::client(status: ClientStatus::Overdue, overdue: 2_000_000). Ids
 * auto-increment for the whole test run; never assert on their exact value.
 * Dates are relative to now(), so travelTo() makes them deterministic.
 */
final class Make
{
    private static int $sequence = 0;

    /**
     * Next id of the shared sequence.
     */
    public static function nextId(): int
    {
        return ++self::$sequence;
    }

    public static function company(
        CompanyCode $code = CompanyCode::SGX,
        ?string $name = null,
        ?int $graceDays = null,
        ?BrandPalette $palette = null,
    ): Company {
        return new Company(
            code: $code,
            name: $name ?? $code->label(),
            graceDays: $graceDays ?? ($code === CompanyCode::SGX ? 15 : 5),
            palette: $palette ?? BrandPalette::official($code),
        );
    }

    public static function userRef(?int $id = null, string $name = 'María Paula Rojas', ?string $email = null): UserRef
    {
        $id ??= self::nextId();

        return new UserRef($id, $name, $email ?? "usuario{$id}@example.test");
    }

    public static function advisor(
        CompanyCode $company = CompanyCode::SGX,
        ?int $code = null,
        string $name = 'Carlos Pérez',
        ?string $email = null,
        bool $active = true,
        ?int $userId = null,
    ): Advisor {
        $code ??= self::nextId();

        return new Advisor($company, $code, $name, $email ?? "asesor{$code}@example.test", $active, $userId);
    }

    public static function contact(
        ?string $name = 'Laura Martínez',
        ?string $email = 'tesoreria@cliente.example',
        ?string $phone = '6015550123',
        ?string $position = 'Tesorera',
        bool $isPrimary = false,
    ): Contact {
        return new Contact($name, $email, $phone, $position, $isPrimary);
    }

    public static function client(
        ?int $id = null,
        CompanyCode $company = CompanyCode::SGX,
        ?string $code = null,
        string $name = 'Ferretería El Tornillo SAS',
        ?string $taxId = null,
        ClientStatus $status = ClientStatus::Current,
        float $balance = 0.0,
        float $overdue = 0.0,
        int $maxDaysOverdue = 0,
        ?Advisor $advisor = null,
        ?UserRef $manager = null,
        ?CarbonImmutable $lastActivityAt = null,
    ): ClientSummary {
        $id ??= self::nextId();

        return new ClientSummary(
            id: $id,
            company: $company,
            code: $code ?? 'C'.(900_000_000 + $id),
            name: $name,
            taxId: $taxId ?? (string) (900_000_000 + $id),
            status: $status,
            balance: $balance,
            overdue: $overdue,
            maxDaysOverdue: $maxDaysOverdue,
            advisor: $advisor,
            manager: $manager,
            lastActivityAt: $lastActivityAt,
        );
    }

    /**
     * @param  list<Contact>|null  $contacts  Null gives one primary contact.
     */
    public static function clientDetail(
        ?ClientSummary $summary = null,
        ?float $creditLimit = 50_000_000.0,
        ?string $group = 'Distribuidores',
        ?string $city = 'Bogotá D.C.',
        ?string $paymentTerms = '30 días',
        ?array $contacts = null,
    ): ClientDetail {
        return new ClientDetail(
            summary: $summary ?? self::client(),
            creditLimit: $creditLimit,
            group: $group,
            city: $city,
            paymentTerms: $paymentTerms,
            contacts: $contacts ?? [self::contact(isPrimary: true)],
        );
    }

    /**
     * The due date defaults to today minus $daysOverdue, the issue date to
     * 30 days before it and the balance to the amount.
     */
    public static function invoice(
        ?int $id = null,
        CompanyCode $company = CompanyCode::SGX,
        int $clientId = 1,
        ?string $documentNumber = null,
        string $documentType = 'Factura',
        ?CarbonImmutable $issuedAt = null,
        ?CarbonImmutable $dueAt = null,
        float $amount = 1_000_000.0,
        ?float $balance = null,
        int $daysOverdue = 0,
        ?int $advisorCode = null,
    ): Invoice {
        $id ??= self::nextId();
        $dueAt ??= CarbonImmutable::today()->subDays($daysOverdue);

        return new Invoice(
            id: $id,
            company: $company,
            clientId: $clientId,
            documentNumber: $documentNumber ?? (string) (10_000 + $id),
            documentType: $documentType,
            issuedAt: $issuedAt ?? $dueAt->subDays(30),
            dueAt: $dueAt,
            amount: $amount,
            balance: $balance ?? $amount,
            daysOverdue: $daysOverdue,
            advisorCode: $advisorCode,
        );
    }

    /**
     * The due date defaults to a week from today.
     */
    public static function commitment(
        ?int $id = null,
        CompanyCode $company = CompanyCode::SGX,
        int $clientId = 1,
        string $clientName = 'Ferretería El Tornillo SAS',
        ?int $advisorCode = null,
        float $amount = 2_500_000.0,
        ?CarbonImmutable $dueDate = null,
        CommitmentStatus $status = CommitmentStatus::Pending,
        ?string $notes = null,
        ?UserRef $createdBy = null,
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $fulfilledAt = null,
    ): Commitment {
        return new Commitment(
            id: $id ?? self::nextId(),
            company: $company,
            clientId: $clientId,
            clientName: $clientName,
            advisorCode: $advisorCode,
            amount: $amount,
            dueDate: $dueDate ?? CarbonImmutable::today()->addDays(7),
            status: $status,
            notes: $notes,
            createdBy: $createdBy ?? self::userRef(),
            createdAt: $createdAt ?? CarbonImmutable::now(),
            fulfilledAt: $fulfilledAt,
        );
    }

    /**
     * System activities (type System or $bySystem) have no user.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public static function activity(
        ?int $id = null,
        int $clientId = 1,
        string $clientName = 'Ferretería El Tornillo SAS',
        ActivityType $type = ActivityType::Call,
        string $summary = 'Llamada a tesorería',
        ?string $details = null,
        ?string $result = 'Promete pagar el viernes',
        ?UserRef $user = null,
        ?CarbonImmutable $occurredAt = null,
        array $metadata = [],
        bool $bySystem = false,
    ): Activity {
        $bySystem = $bySystem || $type === ActivityType::System;

        return new Activity(
            id: $id ?? self::nextId(),
            clientId: $clientId,
            clientName: $clientName,
            type: $type,
            summary: $summary,
            details: $details,
            result: $result,
            user: $bySystem ? null : ($user ?? self::userRef()),
            occurredAt: $occurredAt ?? CarbonImmutable::now(),
            metadata: $metadata,
        );
    }

    /**
     * The cutoff date defaults to today and the reception to now.
     */
    public static function loadRecord(
        ?int $id = null,
        Dataset $dataset = Dataset::Receivables,
        CompanyCode $company = CompanyCode::SGX,
        ?CarbonImmutable $cutoffDate = null,
        LoadSource $source = LoadSource::Robot,
        LoadStatus $status = LoadStatus::Accepted,
        int $rowCount = 120,
        ?float $total = 450_000_000.0,
        ?float $overdue = 95_000_000.0,
        ?CarbonImmutable $receivedAt = null,
        ?UserRef $receivedBy = null,
        ?string $error = null,
    ): LoadRecord {
        return new LoadRecord(
            id: $id ?? self::nextId(),
            dataset: $dataset,
            company: $company,
            cutoffDate: $cutoffDate ?? CarbonImmutable::today(),
            source: $source,
            status: $status,
            rowCount: $rowCount,
            total: $total,
            overdue: $overdue,
            receivedAt: $receivedAt ?? CarbonImmutable::now(),
            receivedBy: $receivedBy,
            error: $error,
        );
    }

    public static function inboundMail(
        ?string $id = null,
        CompanyCode $company = CompanyCode::SGX,
        ?int $clientId = 1,
        string $from = 'tesoreria@cliente.example',
        string $subject = 'RE: Facturas vencidas',
        string $preview = 'Buenos días, el pago queda programado para el viernes.',
        ?CarbonImmutable $receivedAt = null,
        bool $isBounce = false,
        ?string $bounceReason = null,
        ?string $conversationId = null,
        bool $read = false,
        ?string $url = null,
    ): InboundMail {
        return new InboundMail(
            id: $id ?? 'fake-inbound-'.self::nextId(),
            company: $company,
            clientId: $clientId,
            from: $from,
            subject: $subject,
            preview: $preview,
            receivedAt: $receivedAt ?? CarbonImmutable::now(),
            isBounce: $isBounce,
            bounceReason: $bounceReason,
            conversationId: $conversationId,
            read: $read,
            url: $url,
        );
    }

    /**
     * The date defaults to yesterday (movements load the next day) and the
     * whole total to Bancolombia.
     *
     * @param  array<string, float>|null  $byBank
     */
    public static function dailyCollection(
        ?CarbonImmutable $date = null,
        CompanyCode $company = CompanyCode::SGX,
        float $total = 12_500_000.0,
        ?array $byBank = null,
        int $movementCount = 8,
    ): DailyCollection {
        return new DailyCollection(
            date: $date ?? CarbonImmutable::yesterday(),
            company: $company,
            total: $total,
            byBank: $byBank ?? [Bank::Bancolombia->value => $total],
            movementCount: $movementCount,
        );
    }
}
