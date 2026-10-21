<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SellerRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The monthly budget of a period (§7.3, Parte B «Dato que no viene de SAP»):
 * one row per code in force, the edits made on screen and the template.
 * Every change is audited as comisiones.budget.<verb> with the old and the
 * new values. The caller checks PeriodLock first.
 */
final class BudgetSheet
{
    /** Header of the template, as the spec names its columns. */
    public const array HEADER = ['Período', 'Código de vendedor', 'Presupuesto', 'Canal'];

    public function __construct(
        private readonly CodesInForce $codes,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @return list<BudgetRow>
     */
    public function rows(Period $period): array
    {
        $budgets = BudgetRecord::query()
            ->with(['creator', 'editor'])
            ->where('periodo', $period->key())
            ->get()
            ->keyBy('slp_code');
        $rules = $this->codes->of($period);
        $channels = SellerRecord::query()
            ->whereIn('slp_code', array_map(fn ($rule) => $rule->code, $rules))
            ->pluck('canal', 'slp_code');

        $rows = [];

        foreach ($rules as $rule) {
            $channel = $channels[$rule->code] ?? null;
            $rows[] = new BudgetRow($rule, $budgets[$rule->code] ?? null, is_string($channel) && trim($channel) !== '' ? trim($channel) : null);
        }

        return $rows;
    }

    /**
     * Sets the budget of one code: creates it or replaces its value and
     * channel. Returns null when nothing changed.
     */
    public function put(Period $period, int $code, string $amount, ?string $channel, User $user): ?BudgetRecord
    {
        return DB::transaction(function () use ($period, $code, $amount, $channel, $user): ?BudgetRecord {
            $record = BudgetRecord::query()->where('periodo', $period->key())->where('slp_code', $code)->lockForUpdate()->first();
            $old = $record === null ? null : ['valor' => $record->valor, 'canal' => $record->canal];
            $new = ['valor' => $amount, 'canal' => $channel];

            if ($old !== null && self::same($old, $new)) {
                return null;
            }

            if ($record === null) {
                $record = BudgetRecord::query()->create([
                    'periodo' => $period->key(),
                    'slp_code' => $code,
                    'valor' => $amount,
                    'canal' => $channel,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            } else {
                $record->update(['valor' => $amount, 'canal' => $channel, 'updated_by' => $user->id]);
            }

            $this->audit->record(
                event: 'comisiones.budget.updated',
                subjectType: 'presupuesto',
                subjectId: $record->id,
                changes: [
                    'valor' => ['old' => $old['valor'] ?? null, 'new' => $record->valor],
                    'canal' => ['old' => $old['canal'] ?? null, 'new' => $channel],
                ],
                context: ['codigo' => $code, 'origen' => 'pantalla'],
                period: $period->key(),
                user: $user,
            );

            return $record;
        });
    }

    /**
     * Deletes the budget of one code; false when it had none.
     */
    public function delete(Period $period, int $code, User $user): bool
    {
        return DB::transaction(function () use ($period, $code, $user): bool {
            $record = BudgetRecord::query()->where('periodo', $period->key())->where('slp_code', $code)->lockForUpdate()->first();

            if ($record === null) {
                return false;
            }

            $record->delete();

            $this->audit->record(
                event: 'comisiones.budget.deleted',
                subjectType: 'presupuesto',
                subjectId: $record->id,
                changes: [
                    'valor' => ['old' => $record->valor, 'new' => null],
                    'canal' => ['old' => $record->canal, 'new' => null],
                ],
                context: ['codigo' => $code],
                period: $period->key(),
                user: $user,
            );

            return true;
        });
    }

    /**
     * Upserts the rows of a validated template, all or nothing, and audits
     * the load as one event with the old and new value of each code.
     *
     * @param  list<BudgetLine>  $lines
     * @return array{creados: int, actualizados: int, sin_cambios: int}
     */
    public function import(Period $period, array $lines, string $file, User $user): array
    {
        return DB::transaction(function () use ($period, $lines, $file, $user): array {
            $existing = BudgetRecord::query()->where('periodo', $period->key())->lockForUpdate()->get()->keyBy('slp_code');
            $counts = ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
            $changes = [];

            foreach ($lines as $line) {
                $record = $existing[$line->code] ?? null;
                $new = ['valor' => $line->amount, 'canal' => $line->channel];

                if ($record === null) {
                    BudgetRecord::query()->create([
                        'periodo' => $period->key(),
                        'slp_code' => $line->code,
                        ...$new,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ]);
                    $counts['creados']++;
                    $changes["codigo_{$line->code}"] = ['old' => null, 'new' => $new];

                    continue;
                }

                $old = ['valor' => $record->valor, 'canal' => $record->canal];

                if (self::same($old, $new)) {
                    $counts['sin_cambios']++;

                    continue;
                }

                $record->update([...$new, 'updated_by' => $user->id]);
                $counts['actualizados']++;
                $changes["codigo_{$line->code}"] = ['old' => $old, 'new' => $new];
            }

            $this->audit->record(
                event: 'comisiones.budget.imported',
                subjectType: 'presupuesto',
                changes: $changes,
                context: ['archivo' => $file, 'filas' => count($lines), ...$counts],
                period: $period->key(),
                user: $user,
            );

            return $counts;
        });
    }

    /**
     * The template prefilled with the period and its codes: the loaded
     * budget and channel, else the seller master's channel. UTF-8 with BOM
     * and ";" so Excel in Spanish opens it in columns.
     */
    public function template(Period $period): string
    {
        $lines = [self::csvLine(self::HEADER)];

        foreach ($this->rows($period) as $row) {
            $lines[] = self::csvLine([
                $period->key(),
                (string) $row->rule->code,
                $row->budget === null ? '' : Amount::forInput($row->budget->valor),
                $row->channel() ?? '',
            ]);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  array{valor: string, canal: string|null}  $old
     * @param  array{valor: string, canal: string|null}  $new
     */
    private static function same(array $old, array $new): bool
    {
        return BigDecimal::of($old['valor'])->isEqualTo($new['valor']) && $old['canal'] === $new['canal'];
    }

    /**
     * One CSV line. A cell that starts like a formula (=, +, -, @, a tab or a
     * carriage return) gets a leading apostrophe, so Excel shows text that
     * came from SAP instead of running it (OWASP "CSV injection").
     *
     * @param  list<string>  $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(';', array_map(function (string $cell): string {
            if (preg_match('/^[=+\-@\t\r]/', $cell) === 1) {
                $cell = "'".$cell;
            }

            return preg_match('/[;"\r\n]/', $cell) === 1 ? '"'.str_replace('"', '""', $cell).'"' : $cell;
        }, $cells));
    }
}
