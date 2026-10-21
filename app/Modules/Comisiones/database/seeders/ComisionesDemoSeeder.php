<?php

namespace App\Modules\Comisiones\Database\Seeders;

use App\Modules\Comisiones\Application\ApproveSettlement;
use App\Modules\Comisiones\Application\CalculateSettlement;
use App\Modules\Comisiones\Application\CloseSettlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Ingest\AssignmentHistory;
use App\Modules\Comisiones\Infrastructure\Ingest\StagingRows;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientAssignmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CommissionLoad;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\PaymentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SaleRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SellerRecord;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Fictitious Segurex commissions for local development: the SAP sellers of
 * the parameter codes, the Core demo clients of Segurex with their seller,
 * sales and collections of the last months and the current one, budgets,
 * a crossing per month and a few captures waiting for review. Then the last
 * three closed months are settled: the oldest one closed (reports), the
 * next one approved and the previous month as a draft, so the approval flow
 * can be walked with the demo approver.
 *
 * Rows go straight to the staging tables, as the import of an accepted SAP
 * batch leaves them; nothing is queued. Settlements are calculated by the
 * demo portfolio manager and approved by the demo approver, named by e-mail
 * (CoreDemoSeeder), as the settlement stores them. Without Core clients it
 * does nothing. DatabaseSeeder runs it only with APP_ENV=local; it does
 * nothing when there are sales already.
 */
final class ComisionesDemoSeeder extends Seeder
{
    private const string TIMEZONE = 'America/Bogota';

    /** The demo users of CoreDemoSeeder; Comisiones names users by e-mail. */
    private const string ANALYST = 'demo.gerente-cartera@ryr.test';

    private const string APPROVER = 'demo.aprobador-comisiones@ryr.test';

    /**
     * Sellers with commission parameters: code => [name, channel]. 50 is
     * paid on sales only and 85 on collections only.
     *
     * @var array<int, array{string, string}>
     */
    private const array SELLERS = [
        14 => ['MONICA RIVERA AREVALO', 'Distribución'],
        15 => ['PAOLA VARGAS', 'Distribución'],
        16 => ['CAROLINA RAMIREZ', 'Construcción residencial'],
        32 => ['HAROLD MARIN', 'Construcción institucional'],
        41 => ['JENNY ROCIO BUITRAGO', 'Distribución'],
        50 => ['ANDREA HINCAPIE', 'Distribución'],
        85 => ['LILIAM HERRERA', 'Distribución'],
    ];

    /** Months of history before the current one. */
    private const int MONTHS = 4;

    private int $loads = 0;

    private int $documents = 0;

    /**
     * Sales by period key, for the collections of the next month.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $sales = [];

    public function run(CarteraRepository $cartera, CalculateSettlement $calculate, ApproveSettlement $approve, CloseSettlement $close): void
    {
        if (SaleRecord::query()->exists()) {
            return;
        }

        $clients = [...$cartera->asSystem()->allClients(new ClientQuery(companies: [CompanyCode::SGX]))];

        if ($clients === []) {
            return;
        }

        $today = CarbonImmutable::today(self::TIMEZONE);
        $current = Period::of($today->year, $today->month);
        $periods = [];

        for ($back = self::MONTHS; $back >= 0; $back--) {
            $period = $current;

            for ($n = 0; $n < $back; $n++) {
                $period = $period->previous();
            }

            $periods[] = $period;
        }

        $codes = $this->masters($clients);

        foreach ($periods as $index => $period) {
            $this->month($period, $index, $clients, $codes, $today);
        }

        $this->captures($current, $periods[count($periods) - 2]);

        // The three closed months before the current one: closed, approved and draft.
        [$closed, $approved, $draft] = array_slice($periods, -4, 3);
        $at = $today->subDays(2)->setTime(10, 0);

        foreach ([$closed, $approved, $draft] as $period) {
            $settlement = $calculate->handle($period, self::ANALYST, $at);

            if ($period === $draft) {
                continue;
            }

            $justifications = [];

            foreach ($settlement->result->blockingAlerts() as $alert) {
                $justifications[$alert->key()] = 'Revisado con el área comercial; se liquida así en la demostración.';
            }

            $approve->handle($period, self::APPROVER, $at->addHour(), $settlement->revision, $justifications);

            if ($period === $closed) {
                $close->handle($period, self::APPROVER, $at->addHours(2));
            }
        }
    }

    /**
     * The seller and client masters and every client's assignment, as the
     * first SAP load leaves them. Every tenth client sells through code 50.
     *
     * @param  list<ClientSummary>  $clients
     * @return array<int, int> The seller code of each client, by position.
     */
    private function masters(array $clients): array
    {
        $loadId = $this->load(Dataset::SalesRepMaster, CarbonImmutable::today(self::TIMEZONE)->subDay(), count(self::SELLERS));

        $this->stage(SellerRecord::class, 'slp_code', array_map(fn (int $code, array $seller): array => StagingRows::seller([
            'slp_code' => $code,
            'nombre' => $seller[0],
            'estado' => 'activo',
            'correo' => null,
            'canal' => $seller[1],
            'zona' => 'Antioquia',
            'fecha_modificacion' => '2026-01-10',
        ], $loadId), array_keys(self::SELLERS), self::SELLERS));

        $loadId = $this->load(Dataset::CustomerMaster, CarbonImmutable::today(self::TIMEZONE)->subDay(), count($clients));
        $codes = [];
        $rows = [];

        foreach ($clients as $i => $client) {
            $codes[$i] = $i % 10 === 9 ? 50 : ($client->advisor->code ?? 14);
            $rows[] = StagingRows::client([
                'codigo_cliente' => $client->code,
                'nit' => explode('-', $client->taxId)[0],
                'razon_social' => $client->name,
                'slp_code' => $codes[$i],
                'vendedor' => self::SELLERS[$codes[$i]][0] ?? null,
                'canal' => self::SELLERS[$codes[$i]][1] ?? null,
                'estado' => 'activo',
            ], $loadId);
        }

        $this->stage(ClientRecord::class, 'codigo_cliente', $rows);

        $this->stage(ClientAssignmentRecord::class, 'id', array_map(fn (array $row): array => [
            'codigo_cliente' => $row['codigo_cliente'],
            'slp_code' => $row['slp_code'],
            'vigente_desde' => AssignmentHistory::FIRST_SIGHTING,
            'load_id' => $loadId,
        ], $rows));

        return $codes;
    }

    /**
     * One month of SAP data: sales (and a credit note), the collection of
     * last month's sales, a crossing and the sellers' budgets.
     *
     * @param  list<ClientSummary>  $clients
     * @param  array<int, int>  $codes
     */
    private function month(Period $period, int $index, array $clients, array $codes, CarbonImmutable $today): void
    {
        $start = CarbonImmutable::parse($period->start()->format('Y-m-d'), self::TIMEZONE);
        $isCurrent = $start->isSameMonth($today);
        $lastDay = $isCurrent ? max(1, $today->day - 1) : $start->daysInMonth;
        $day = fn (int $n): string => $start->setDay(min($lastDay, 1 + $n % 26))->toDateString();
        $sales = [];

        foreach ($clients as $i => $client) {
            for ($n = 0; $n <= ($i + $index) % 2; $n++) {
                $total = (2 + ($i * 7 + $n * 5 + $index * 3) % 25) * 400_000;
                $sales[] = $this->sale($client, $codes[$i], $day($i * 3 + $n * 11), $total);
            }
        }

        // A credit note on the first sale of the month.
        $sales[] = $this->sale($clients[0], $codes[0], $day(20), -(int) round((float) $sales[0]['total'] * 0.1), 14);

        $cutoff = $isCurrent ? $today->subDay() : $start->endOfMonth()->startOfDay();
        $this->sales[$period->key()] = $sales;
        $loadId = $this->load(Dataset::Sales, $cutoff, count($sales));
        $this->stage(SaleRecord::class, 'clave', array_map(fn (array $row): array => StagingRows::sale($row, $loadId), $sales));

        $payments = [];

        foreach ($this->sales[$period->previous()->key()] ?? [] as $n => $sale) {
            if ($sale['tipo_objeto'] !== SaleRecord::INVOICE || $n % 7 === 3) {
                continue;
            }

            $share = $n % 4 === 0 ? 0.5 : 1.0;
            $payments[] = $this->payment($sale, $day(4 + $n * 2), $share, $codes, $clients);
        }

        if ($payments !== []) {
            $loadId = $this->load(Dataset::Collections, $cutoff, count($payments));
            $this->stage(PaymentRecord::class, 'clave', array_map(fn (array $row): array => StagingRows::payment($row, $loadId), $payments));
        }

        $invoice = $sales[1];
        $this->stage(CrossingRecord::class, 'clave', [StagingRows::crossing([
            'numero_cruce' => 9000 + $index,
            'linea' => 0,
            'fecha_cruce' => $day(18),
            'tipo_cruce' => 'anticipo',
            'tipo_conciliacion' => 0,
            'estado' => 'vigente',
            'nit' => $invoice['nit'],
            'codigo_cliente' => $invoice['codigo_cliente'],
            'razon_social' => $invoice['cliente'],
            'doc_entry_factura' => $invoice['doc_entry'],
            'numero_factura' => $invoice['numero_documento'],
            'fecha_factura' => $invoice['fecha_documento'],
            'slp_code_factura' => $invoice['slp_code'],
            'vendedor_factura' => $invoice['vendedor'],
            'valor_factura' => sprintf('%.2f', $invoice['total'] * 1.19),
            'base_gravable' => $invoice['total'],
            'iva_factura' => $invoice['iva'],
            'valor_compensado' => sprintf('%.2f', $invoice['total'] * 1.19),
            'valor_compensado_sin_iva' => $invoice['total'],
            'usuario' => 'manager',
        ], $this->load(Dataset::Offsets, $cutoff, 1))]);

        $this->budgets($period, $sales, $payments, $index);
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(ClientSummary $client, int $code, string $date, int $total, int $type = SaleRecord::INVOICE): array
    {
        $number = ++$this->documents;

        return [
            'tipo_objeto' => $type,
            'doc_entry' => 5000 + $number,
            'numero_documento' => 80_000 + $number,
            'linea' => 0,
            'clase_documento' => $type === SaleRecord::INVOICE ? 'factura' : 'nota_credito',
            'estado_documento' => 'cerrado',
            'fecha_documento' => $date,
            'fecha_contabilizacion' => $date,
            'slp_code' => $code,
            'vendedor' => self::SELLERS[$code][0] ?? null,
            'canal' => self::SELLERS[$code][1] ?? null,
            'codigo_cliente' => $client->code,
            'cliente' => $client->name,
            'nit' => explode('-', $client->taxId)[0],
            'ciudad' => null,
            'codigo_articulo' => 'A-'.(1 + $number % 9),
            'descripcion' => 'Elementos de protección',
            'total' => sprintf('%.2f', $total),
            'iva' => sprintf('%.2f', $total * 0.19),
            'moneda' => '$',
            'trm' => null,
        ];
    }

    /**
     * The collection of $share of a sale. Collections of code 50 go to 85,
     * which is paid on collections only.
     *
     * @param  array<string, mixed>  $sale
     * @param  array<int, int>  $codes
     * @param  list<ClientSummary>  $clients
     * @return array<string, mixed>
     */
    private function payment(array $sale, string $date, float $share, array $codes, array $clients): array
    {
        $number = ++$this->documents;
        $base = (float) $sale['total'];
        $code = $sale['slp_code'] === 50 ? 85 : $sale['slp_code'];

        return [
            'doc_entry_pago' => 7000 + $number,
            'numero_pago' => 3000 + $number,
            'linea' => 0,
            'fecha_pago' => $date,
            'estado_pago' => 'vigente',
            'tipo_documento' => SaleRecord::INVOICE,
            'doc_entry_factura' => $sale['doc_entry'],
            'numero_factura' => $sale['numero_documento'],
            'fecha_factura' => $sale['fecha_documento'],
            'fecha_vencimiento' => CarbonImmutable::parse($sale['fecha_documento'])->addDays(30)->toDateString(),
            'codigo_cliente' => $sale['codigo_cliente'],
            'nombre_cliente' => $sale['cliente'],
            'slp_code' => $code,
            'vendedor' => self::SELLERS[$code][0],
            'valor_factura' => sprintf('%.2f', $base * 1.19),
            'base_gravable' => sprintf('%.2f', $base),
            'iva_factura' => sprintf('%.2f', $base * 0.19),
            'descuento_factura' => null,
            'moneda' => 'COP',
            'trm' => '1.000000',
            'valor_aplicado' => sprintf('%.2f', $base * 1.19 * $share),
            'valor_pagado_sin_iva' => sprintf('%.2f', $base * $share),
            'comision_sap' => null,
        ];
    }

    /**
     * Each seller's budget: what they sold (or collected, for 85), give or
     * take, so the months land above and below the minimum.
     *
     * @param  list<array<string, mixed>>  $sales
     * @param  list<array<string, mixed>>  $payments
     */
    private function budgets(Period $period, array $sales, array $payments, int $index): void
    {
        $totals = array_fill_keys(array_keys(self::SELLERS), 0.0);

        foreach ($sales as $sale) {
            $totals[$sale['slp_code']] += (float) $sale['total'];
        }

        foreach ($payments as $payment) {
            if ($payment['slp_code'] === 85) {
                $totals[85] += (float) $payment['valor_pagado_sin_iva'];
            }
        }

        foreach ($totals as $code => $total) {
            $factor = [0.92, 1.0, 1.08, 1.2][($code + $index) % 4];

            BudgetRecord::query()->create([
                'periodo' => $period->key(),
                'slp_code' => $code,
                'valor' => sprintf('%.2f', max(5_000_000, round($total * $factor, -5))),
                'canal' => self::SELLERS[$code][1],
            ]);
        }
    }

    /**
     * An approved OBR in the previous month, and captures waiting for review
     * in the previous and the current month.
     */
    private function captures(Period $current, Period $previous): void
    {
        ObrRecord::query()->create([
            'periodo' => $previous->key(),
            'slp_code' => 14,
            'descripcion' => 'Obra Torre Empresarial, etapa 2',
            'valor_vendido' => '48000000.00',
            'tasa' => '1.5',
            'estado' => CaptureStatus::Approved,
            'revisado_en' => now(),
        ]);

        AdjustmentRecord::query()->create([
            'periodo' => $previous->key(),
            'slp_code' => 41,
            'tipo' => 'descuento',
            'valor' => '350000.00',
            'descripcion' => 'Anticipo de comisión pagado en julio',
            'estado' => CaptureStatus::Pending,
        ]);

        ObrRecord::query()->create([
            'periodo' => $current->key(),
            'slp_code' => 16,
            'descripcion' => 'Conjunto residencial Los Almendros',
            'valor_vendido' => '26500000.00',
            'tasa' => '2',
            'estado' => CaptureStatus::Pending,
        ]);

        AdjustmentRecord::query()->create([
            'periodo' => $current->key(),
            'slp_code' => 32,
            'tipo' => 'reintegro',
            'valor' => '420000.00',
            'descripcion' => 'Reintegro por devolución mal descontada',
            'estado' => CaptureStatus::Pending,
        ]);

        $crossing = CrossingRecord::query()->orderByDesc('fecha_cruce')->value('clave');

        ReassignmentRecord::query()->create([
            'clave_cruce' => $crossing,
            'slp_code' => 15,
            'motivo' => 'El anticipo lo gestionó la asesora de la zona',
            'estado' => CaptureStatus::Pending,
        ]);
    }

    /**
     * The accepted SAP load that brought $rows of $dataset, cut on $cutoff
     * and received the next morning.
     */
    private function load(Dataset $dataset, CarbonImmutable $cutoff, int $rows): int
    {
        CommissionLoad::query()->create([
            'load_id' => ++$this->loads,
            'dataset' => $dataset->value,
            'fuente' => 'robot',
            'corte' => $cutoff->toDateString(),
            'filas' => $rows,
            'insertadas' => $rows,
            'actualizadas' => 0,
            'recibida_en' => $cutoff->addDay()->setTime(6, 5),
            'estado' => CommissionLoad::ACCEPTED,
        ]);

        return $this->loads;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows
     */
    private function stage(string $model, string $key, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            if ($key === 'id') {
                $model::query()->insert(array_map(fn (array $row): array => [...$row, 'created_at' => now(), 'updated_at' => now()], $chunk));

                continue;
            }

            $model::query()->upsert($chunk, [$key], array_values(array_diff(array_keys($chunk[0]), [$key])));
        }
    }
}
