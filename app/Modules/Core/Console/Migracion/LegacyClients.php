<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;

/**
 * Finds the client of this app a legacy row belongs to. The app's clients
 * (not deleted) are read once, into memory, when it is loaded.
 *
 * - Fase 1 (fase1(), by clients.id of Fase 1): first by the SAP codes the
 *   client had on its invoices (fase1_client_codes.csv) that exist in its
 *   company; then by NIT, comparing digits only and allowing one trailing
 *   check digit on either side (900.123.456 matches 900123456-7).
 * - Supabase (supabase(), by codigo_sn): the SGX client with that code.
 *
 * A client that cannot be told apart, or is not in the app, throws
 * RowRejected with the reason; each Fase 1 client is resolved once.
 */
final class LegacyClients
{
    /**
     * @var array<string, array<string, TargetClient>> Company => SAP code => client.
     */
    private array $byCode = [];

    /**
     * @var array<string, array<string, list<TargetClient>>> Company => NIT digits => clients.
     */
    private array $byTaxId = [];

    /**
     * @var array<int, array{company: CompanyCode, taxId: string}> The clients of fase1_clients.csv, by their id.
     */
    private array $fase1 = [];

    /**
     * @var array<int, list<string>> The SAP codes of each Fase 1 client, by its id.
     */
    private array $codes = [];

    /**
     * @var array<int, TargetClient|string> Each Fase 1 client already resolved, or why it cannot be.
     */
    private array $resolved = [];

    private function __construct() {}

    /**
     * Reads the app's clients and the Fase 1 lookup files present. A lookup
     * row that cannot be read is left out, with a warning on $report.
     */
    public static function load(?CsvFile $fase1Clients, ?CsvFile $fase1Codes, ImportReport $report): self
    {
        $clients = new self;

        foreach (ClientRecord::query()->get(['id', 'company', 'code', 'tax_id', 'advisor_code']) as $record) {
            $client = new TargetClient($record->id, $record->company, $record->code, $record->advisor_code);
            $clients->byCode[$client->company->value][$client->code] = $client;
            $clients->byTaxId[$client->company->value][self::digits($record->tax_id)][] = $client;
        }

        $report->lookup($fase1Clients, function (CsvRow $row) use ($clients): void {
            $clients->fase1[$row->int('id')] = [
                'company' => CompanyCode::from($row->oneOf('empresa', ['SGX', 'SDC'])),
                'taxId' => $row->optionalString('nit') ?? '',
            ];
        });

        $report->lookup($fase1Codes, function (CsvRow $row) use ($clients): void {
            $clients->codes[$row->int('client_id')][] = $row->string('codigo_sn');
        });

        return $clients;
    }

    /**
     * The client of the Fase 1 client $legacyId (clients.id in Fase 1).
     *
     * @throws RowRejected
     */
    public function fase1(int $legacyId): TargetClient
    {
        $client = $this->resolved[$legacyId] ??= $this->resolveFase1($legacyId);

        return $client instanceof TargetClient ? $client : throw new RowRejected($client);
    }

    /**
     * The client of a fase1_actions.csv row (client_id), which must be of
     * the row's company (empresa).
     *
     * @throws RowRejected
     */
    public function ofAction(CsvRow $row): TargetClient
    {
        $company = CompanyCode::from($row->oneOf('empresa', ['SGX', 'SDC']));
        $client = $this->fase1($row->int('client_id'));

        if ($client->company !== $company) {
            throw new RowRejected("la empresa de la gestión ({$company->value}) no coincide con la del cliente ({$client->company->value})");
        }

        return $client;
    }

    /**
     * The SGX client with SAP code $code (Supabase codigo_sn).
     *
     * @throws RowRejected
     */
    public function supabase(string $code): TargetClient
    {
        return $this->byCode[CompanyCode::SGX->value][$code]
            ?? throw new RowRejected("cliente no existe en la app (SGX, código {$code})");
    }

    private function resolveFase1(int $legacyId): TargetClient|string
    {
        $legacy = $this->fase1[$legacyId] ?? null;

        if ($legacy === null) {
            return "el cliente {$legacyId} no está en fase1_clients.csv";
        }

        $company = $legacy['company']->value;
        $byCode = [];

        foreach ($this->codes[$legacyId] ?? [] as $code) {
            $client = $this->byCode[$company][$code] ?? null;

            if ($client !== null) {
                $byCode[$client->id] = $client;
            }
        }

        if (count($byCode) > 1) {
            $codes = implode(', ', array_map(fn (TargetClient $client): string => $client->code, $byCode));

            return "el cliente {$legacyId} tiene varios códigos SAP en la app: {$codes}";
        }

        if ($byCode !== []) {
            return array_values($byCode)[0];
        }

        $digits = self::digits($legacy['taxId']);
        $matches = $digits === '' ? [] : $this->byNit($company, $digits);

        return match (count($matches)) {
            1 => array_values($matches)[0],
            0 => $digits === ''
                ? "cliente no existe en la app ({$company}, sin NIT)"
                : "cliente no existe en la app ({$company}, NIT {$digits})",
            default => "NIT ambiguo: {$digits} coincide con varios clientes de la app ({$company})",
        };
    }

    /**
     * The clients of $company whose NIT has the digits $digits, or those
     * digits plus or minus one trailing check digit.
     *
     * @return array<int, TargetClient>
     */
    private function byNit(string $company, string $digits): array
    {
        $candidates = [$digits, ...array_map(fn (int $check): string => $digits.$check, range(0, 9))];

        if (strlen($digits) > 1) {
            $candidates[] = substr($digits, 0, -1);
        }

        $matches = [];

        foreach ($candidates as $candidate) {
            foreach ($this->byTaxId[$company][$candidate] ?? [] as $client) {
                $matches[$client->id] = $client;
            }
        }

        return $matches;
    }

    private static function digits(string $taxId): string
    {
        return (string) preg_replace('/\D/', '', $taxId);
    }
}
