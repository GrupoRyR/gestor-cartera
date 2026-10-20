<?php

namespace Tests\Modules\Comisiones\Feature\Concerns;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use RuntimeException;
use Tests\TestCase;

/**
 * August 2026 with collections, staged through Ingesta, for the reports and
 * controls screens:
 *
 * - code 14 (Mónica Rivera): invoice 8225 of 28/04 paid on 05/08 (99 days,
 *   2,100,840 at 50 %: 7,878.15), invoice 9098 of 30/06 paid on 11/08
 *   (42 days, 10,560,866 at 100 %: 79,206.495) and a crossing of invoice
 *   8001 of 30/06 on 20/08 (51 days, 1,000,000 without VAT: 7,500), a
 *   collection commission of 94,585 over 13,661,706;
 * - code 85 (Liliam Herrera): the four Annex 2 payments, 5,548,738 at 1.5 %
 *   without tranches (83,231), and 85.1 (Sergio Caballero) 1 % of them (55,487);
 * - code 50: one sale and no collections, so no collection report.
 */
final class AugustCollections
{
    public const string PERIOD = '2026-08';

    public static function stage(SapBatches $sap): void
    {
        $sap->send(Dataset::CustomerMaster, [SapBatches::client()], cutoff: '2026-08-31');
        $sap->send(Dataset::Sales, [
            SapBatches::sale(['doc_entry' => 5900, 'numero_documento' => 8900, 'slp_code' => 50, 'vendedor' => 'CODIGO 50', 'fecha_documento' => '2026-08-14', 'fecha_contabilizacion' => '2026-08-14']),
        ], cutoff: '2026-08-31');

        $liliam = fn (int $line, string $paid, string $date, string $invoiceDate) => SapBatches::payment([
            'doc_entry_pago' => 7100 + $line,
            'numero_pago' => 3100 + $line,
            'slp_code' => 85,
            'vendedor' => 'LILIAM HERRERA',
            'numero_factura' => 8500 + $line,
            'doc_entry_factura' => 5500 + $line,
            'codigo_cliente' => 'C800111222',
            'nombre_cliente' => 'Constructora Andina SAS',
            'fecha_pago' => $date,
            'fecha_factura' => $invoiceDate,
            'valor_factura' => '3000000.00',
            'valor_pagado_sin_iva' => $paid,
        ]);

        $sap->send(Dataset::Collections, [
            SapBatches::payment([
                'doc_entry_pago' => 7001, 'numero_pago' => 3001, 'numero_factura' => 8225, 'doc_entry_factura' => 5225,
                'fecha_pago' => '2026-08-05', 'fecha_factura' => '2026-04-28', 'valor_factura' => '2500000.00', 'valor_pagado_sin_iva' => '2100840.00',
            ]),
            SapBatches::payment([
                'doc_entry_pago' => 7002, 'numero_pago' => 3002, 'numero_factura' => 9098, 'doc_entry_factura' => 6098,
                'fecha_pago' => '2026-08-11', 'fecha_factura' => '2026-06-30', 'valor_factura' => '12567431.00', 'valor_pagado_sin_iva' => '10560866.00',
            ]),
            $liliam(1, '2000000.00', '2026-08-10', '2026-07-31'),
            $liliam(2, '1500000.00', '2026-08-12', '2026-06-03'),
            $liliam(3, '1048738.00', '2026-08-18', '2026-05-10'),
            $liliam(4, '1000000.00', '2026-08-25', '2026-02-06'),
        ], cutoff: '2026-08-31');

        $sap->send(Dataset::Offsets, [
            SapBatches::crossing(['fecha_cruce' => '2026-08-20']),
        ], cutoff: '2026-08-31');
    }

    /**
     * Calculates the period as the analyst, approves it as the approver and
     * closes it, through the screens.
     */
    public static function close(TestCase $test, User $analyst, User $approver, string $period = self::PERIOD): void
    {
        $test->actingAs($analyst)->post("/comisiones/periodos/{$period}/calcular");
        $revision = (int) SettlementRecord::query()->where('periodo', $period)->orderByDesc('version')->value('revision');
        $test->actingAs($approver)->post("/comisiones/periodos/{$period}/aprobar", ['revision' => $revision]);
        $test->actingAs($approver)->post("/comisiones/periodos/{$period}/cerrar");

        if (SettlementRecord::query()->where('periodo', $period)->orderByDesc('version')->value('estado') !== 'cerrada') {
            throw new RuntimeException("The settlement of {$period} was not closed.");
        }
    }
}
