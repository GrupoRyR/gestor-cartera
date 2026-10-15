<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Where a batch came from.
 */
enum LoadSource: string
{
    use HasOptions;

    /** The ETL robot, through the signed endpoint. */
    case Robot = 'robot';

    /** A user uploaded the Excel file as a fallback when the robot fails. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Robot => 'Robot',
            self::Manual => 'Carga manual',
        };
    }
}
