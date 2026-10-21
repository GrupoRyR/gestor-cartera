<?php

namespace Tests\Modules\Comisiones\Feature\Concerns;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use RuntimeException;
use Tests\TestCase;

/**
 * Takes a period through the screens up to a closed settlement (calculate,
 * approve by another user, close), so the capture screens see it locked.
 */
final class ClosedPeriods
{
    /**
     * Stages one sale of code 50 (no minimum, so no budget is needed) dated
     * in the period and closes it.
     */
    public static function close(TestCase $test, SapBatches $sap, User $analyst, User $approver, string $period = '2026-07'): void
    {
        $sap->send(Dataset::Sales, [SapBatches::sale(['doc_entry' => 5900, 'numero_documento' => 8900, 'slp_code' => 50, 'vendedor' => 'CODIGO 50', 'fecha_documento' => "{$period}-15", 'fecha_contabilizacion' => "{$period}-15"])]);

        $test->actingAs($analyst)->post("/comisiones/periodos/{$period}/calcular");
        $revision = (int) SettlementRecord::query()->where('periodo', $period)->orderByDesc('version')->value('revision');
        $test->actingAs($approver)->post("/comisiones/periodos/{$period}/aprobar", ['revision' => $revision]);
        $test->actingAs($approver)->post("/comisiones/periodos/{$period}/cerrar");

        if (SettlementRecord::query()->where('periodo', $period)->orderByDesc('version')->value('estado') !== 'cerrada') {
            throw new RuntimeException("The settlement of {$period} was not closed.");
        }
    }
}
