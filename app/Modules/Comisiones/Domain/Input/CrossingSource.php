<?php

namespace App\Modules\Comisiones\Domain\Input;

/**
 * Where a crossing comes from: the SAP report or a manual capture with
 * support (§4 "CruceSAP / CruceManual").
 */
enum CrossingSource: string
{
    case Sap = 'sap';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Sap => 'SAP',
            self::Manual => 'Manual',
        };
    }
}
