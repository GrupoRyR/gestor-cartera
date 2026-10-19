<?php

namespace App\Modules\Ingesta\Application\Manual;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\IngestaSink;

/**
 * Fills "slp_code" and "email_asesor", which the SAP Excel export lacks.
 * Core assigns each client's advisor from slp_code, so a null would leave
 * the client without one.
 *
 * First by advisor name: the cartera column "asesor" against "nombre" of
 * the company's current asesores load (trimmed, case-insensitive). Else by
 * client: the advisor of the same "codigo_sn" in the company's current
 * cartera load. Else both stay null.
 */
final class AdvisorCodes
{
    public function __construct(
        private readonly IngestaSink $sink,
    ) {}

    /**
     * The rows with the advisor columns filled where they were null.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @return list<array<string, scalar|null>>
     */
    public function fill(array $rows, CompanyCode $company): array
    {
        $pending = array_filter($rows, fn (array $row): bool => $row['slp_code'] === null);

        if ($pending === []) {
            return $rows;
        }

        $byName = $this->byName($company);
        $byClient = $this->byClient($company);

        foreach (array_keys($pending) as $index) {
            $row = $rows[$index];
            $name = is_string($row['asesor']) ? self::key($row['asesor']) : '';
            $client = is_string($row['codigo_sn']) ? $row['codigo_sn'] : '';
            $advisor = ($name !== '' ? $byName[$name] ?? null : null) ?? ($client !== '' ? $byClient[$client] ?? null : null);

            if ($advisor !== null) {
                $rows[$index]['slp_code'] = $advisor['slp_code'];
                $rows[$index]['email_asesor'] = $row['email_asesor'] ?? $advisor['email'];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array{slp_code: int, email: ?string}>
     */
    private function byName(CompanyCode $company): array
    {
        $load = $this->sink->latest(Dataset::Advisors, $company);
        $advisors = [];

        foreach ($load === null ? [] : $this->sink->rows($load->id) as $row) {
            $name = is_string($row['nombre'] ?? null) ? self::key($row['nombre']) : '';

            if ($name !== '' && is_int($row['slp_code'] ?? null)) {
                $advisors[$name] ??= ['slp_code' => $row['slp_code'], 'email' => is_string($row['email'] ?? null) ? $row['email'] : null];
            }
        }

        return $advisors;
    }

    /**
     * @return array<string, array{slp_code: int, email: ?string}>
     */
    private function byClient(CompanyCode $company): array
    {
        $load = $this->sink->latest(Dataset::Receivables, $company);
        $advisors = [];

        foreach ($load === null ? [] : $this->sink->rows($load->id) as $row) {
            $client = $row['codigo_sn'] ?? null;

            if (is_string($client) && is_int($row['slp_code'] ?? null)) {
                $advisors[$client] ??= ['slp_code' => $row['slp_code'], 'email' => is_string($row['email_asesor'] ?? null) ? $row['email_asesor'] : null];
            }
        }

        return $advisors;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
