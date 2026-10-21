<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientAssignmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The crossings of a period a user may reassign to another seller (§5.4):
 * the SAP crossings and the approved manual ones dated in it, not annulled,
 * each with the seller it goes to today. That seller follows the engine
 * (CrossingResolver): the latest approved reassignment, else the seller
 * captured on a manual crossing, else the client's assignment on the
 * crossing date; null when there is none.
 */
final class CrossingChoices
{
    /**
     * @return Builder<CrossingRecord>
     */
    public function query(Period $period): Builder
    {
        return CrossingRecord::query()
            ->where('estado', CaptureStatus::Approved->value)
            ->where('estado_cruce', '!=', 'anulado')
            ->whereBetween('fecha_cruce', [$period->start()->format('Y-m-d'), $period->end()->format('Y-m-d')]);
    }

    /**
     * @return Collection<int, CrossingRecord>
     */
    public function of(Period $period): Collection
    {
        return $this->query($period)->orderBy('fecha_cruce')->orderBy('id')->get();
    }

    /**
     * Key => "Factura 8001 · Ferretería El Tornillo SAS · 22/07/2026 · vendedor 14", for a select.
     *
     * @return array<string, string>
     */
    public function options(Period $period): array
    {
        $crossings = $this->of($period);
        $sellers = $this->sellers($crossings);
        $options = [];

        foreach ($crossings as $crossing) {
            $options[$crossing->clave] = __('comisiones::ajustes.reasignaciones.opcion', [
                'factura' => $crossing->numero_factura ?? (string) ($crossing->doc_entry_factura ?? '—'),
                'cliente' => $crossing->razon_social ?? $crossing->codigo_cliente,
                'fecha' => $crossing->fecha_cruce->format('d/m/Y'),
                'vendedor' => $sellers[$crossing->clave] ?? __('comisiones::ajustes.reasignaciones.sin_vendedor'),
            ]);
        }

        return $options;
    }

    /**
     * The seller each crossing goes to today, by key; missing when none.
     *
     * @param  iterable<CrossingRecord>  $crossings
     * @return array<string, int>
     */
    public function sellers(iterable $crossings): array
    {
        $crossings = collect($crossings);
        $overrides = ReassignmentRecord::query()
            ->approved()
            ->whereIn('clave_cruce', $crossings->pluck('clave')->all())
            ->orderBy('revisado_en')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ReassignmentRecord $row): array => [$row->clave_cruce => $row->slp_code])
            ->all();
        $history = ClientAssignmentRecord::query()
            ->whereIn('codigo_cliente', $crossings->pluck('codigo_cliente')->unique()->all())
            ->orderBy('vigente_desde')
            ->get()
            ->groupBy('codigo_cliente');
        $sellers = [];

        foreach ($crossings as $crossing) {
            $seller = $overrides[$crossing->clave] ?? $crossing->slp_code_capturado;

            if ($seller === null) {
                foreach ($history[$crossing->codigo_cliente] ?? [] as $assignment) {
                    if ($assignment->vigente_desde->greaterThan($crossing->fecha_cruce)) {
                        break;
                    }

                    $seller = $assignment->slp_code;
                }
            }

            if ($seller !== null) {
                $sellers[$crossing->clave] = $seller;
            }
        }

        return $sellers;
    }
}
