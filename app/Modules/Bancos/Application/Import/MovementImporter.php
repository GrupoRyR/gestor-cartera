<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Bancos\Domain\Statement\StatementMovement;
use App\Modules\Bancos\Domain\Statement\StatementReading;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores the credits of a statement. The one way in for the folder, the app
 * and the Treasury API, so they cannot disagree.
 *
 * Only credits are kept (debits are Treasury's). A credit already stored is
 * skipped, so the same statement, or an overlapping one, can be loaded again:
 * the fingerprint is the company, the bank, the date, the amount and the
 * texts, plus the position of the movement among identical ones in the file,
 * so two real payments of the same amount with the same reference on the same
 * day are both kept, yet loading that file twice adds nothing.
 *
 * Credits dated after today are not stored: a bank cannot post tomorrow's
 * money, so it is a date read in the wrong order (day and month swapped).
 */
final class MovementImporter
{
    private const int CHUNK = 500;

    /** Errors kept on the load; the count says how many there were. */
    private const int MAX_STORED_ERRORS = 50;

    private const int MAX_DESCRIPTION = 500;

    private const int MAX_REFERENCE = 100;

    private const int MAX_ACCOUNT = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    public function import(
        Bank $bank,
        CompanyCode $company,
        StatementReading $reading,
        ImportSource $source,
        ?string $fileName = null,
        ?string $fileSha256 = null,
        ?int $userId = null,
    ): ImportResult {
        $today = CarbonImmutable::now(Cells::TIMEZONE)->startOfDay();

        $credits = [];
        $future = [];

        foreach ($reading->credits() as $movement) {
            if ($movement->date->greaterThan($today)) {
                $future[] = $movement;
            } else {
                $credits[] = $movement;
            }
        }

        $load = DB::transaction(function () use ($bank, $company, $reading, $source, $fileName, $fileSha256, $userId, $credits, $future): BankLoad {
            $load = BankLoad::query()->create([
                'company' => $company,
                'bank' => $bank,
                'source' => $source,
                'file_name' => $fileName === null ? null : mb_substr($fileName, 0, 255),
                'file_sha256' => $fileSha256,
                'movements_read' => count($reading->movements),
                'credits' => count($credits) + count($future),
                'debits_skipped' => $reading->debitCount(),
                'ignored_rows' => $reading->ignoredRows,
                'user_id' => $userId,
            ]);

            $inserted = $this->store($load, $bank, $company, $credits);
            $errors = $this->errors($reading, $future);

            $load->update([
                'inserted' => $inserted,
                'duplicates' => count($credits) - $inserted,
                'error_count' => count($reading->errors) + count($future),
                'errors' => array_slice($errors, 0, self::MAX_STORED_ERRORS),
            ]);

            return $load;
        });

        $this->audit->record('bancos.carga.imported', 'bancos_carga', $load->id, [], [
            'bank' => $bank->value,
            'company' => $company->value,
            'source' => $source->value,
            'inserted' => $load->inserted,
            'duplicates' => $load->duplicates,
            'errors' => $load->error_count,
        ]);

        return new ImportResult($load);
    }

    /**
     * Leaves a trace in the history of a file that could not be imported, so a
     * statement that fails unattended (the daily folder) does not go unnoticed.
     */
    public function recordFailure(
        Bank $bank,
        CompanyCode $company,
        ImportSource $source,
        string $message,
        ?string $fileName = null,
        ?string $fileSha256 = null,
        ?int $userId = null,
    ): BankLoad {
        $load = BankLoad::query()->create([
            'company' => $company,
            'bank' => $bank,
            'source' => $source,
            'failed' => true,
            'file_name' => $fileName === null ? null : mb_substr($fileName, 0, 255),
            'file_sha256' => $fileSha256,
            'error_count' => 1,
            'errors' => [['row' => null, 'message' => $message]],
            'user_id' => $userId,
        ]);

        $this->audit->record('bancos.carga.failed', 'bancos_carga', $load->id, [], [
            'bank' => $bank->value,
            'company' => $company->value,
            'source' => $source->value,
        ]);

        return $load;
    }

    /**
     * @param  list<StatementMovement>  $credits
     * @return int Rows actually inserted.
     */
    private function store(BankLoad $load, Bank $bank, CompanyCode $company, array $credits): int
    {
        $now = now();
        $seen = [];
        $rows = [];

        foreach ($credits as $credit) {
            $key = implode('|', [
                $credit->date->toDateString(),
                $credit->amount,
                $credit->currency,
                self::normalize($credit->reference),
                self::normalize($credit->description),
                self::normalize($credit->account),
            ]);
            $position = $seen[$key] = ($seen[$key] ?? 0) + 1;

            $rows[] = [
                'load_id' => $load->id,
                'company' => $company->value,
                'bank' => $bank->value,
                'movement_date' => $credit->date->toDateString(),
                'amount' => $credit->amount,
                'currency' => $credit->currency,
                'description' => $credit->description === null ? null : mb_substr($credit->description, 0, self::MAX_DESCRIPTION),
                'reference' => $credit->reference === null ? null : mb_substr($credit->reference, 0, self::MAX_REFERENCE),
                'account' => $credit->account === null ? null : mb_substr($credit->account, 0, self::MAX_ACCOUNT),
                'fingerprint' => hash('sha256', "{$company->value}|{$bank->value}|{$key}|{$position}"),
                'status' => MovementStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $inserted += BankMovement::query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * @param  list<StatementMovement>  $future
     * @return list<array{row: int|null, message: string}>
     */
    private function errors(StatementReading $reading, array $future): array
    {
        $errors = [];

        if ($future !== []) {
            $errors[] = [
                'row' => null,
                'message' => sprintf(
                    '%d crédito(s) con fecha posterior a hoy no se cargaron (el primero: %s). Revisa que el archivo sea del banco elegido y que las fechas se lean en el orden correcto.',
                    count($future),
                    $future[0]->date->format('d/m/Y'),
                ),
            ];
        }

        foreach ($reading->errors as $error) {
            $errors[] = ['row' => $error->row, 'message' => $error->message];
        }

        return $errors;
    }

    /**
     * Texts compared without case or spacing, so two exports of the same
     * statement fingerprint the same.
     */
    private static function normalize(?string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim((string) $text)));
    }
}
