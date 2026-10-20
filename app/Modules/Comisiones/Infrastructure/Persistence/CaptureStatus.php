<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

/**
 * The review state of a manual capture (OBR, adjustment, manual crossing,
 * reassignment). Only approved captures enter a calculation; SAP crossings
 * are stored as approved.
 */
enum CaptureStatus: string
{
    case Pending = 'pendiente';
    case Approved = 'aprobado';
    case Rejected = 'rechazado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
        };
    }
}
