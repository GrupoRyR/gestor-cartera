<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Batches the ETL robot publishes to Ingesta (Part A.4).
 *
 * The value is the {lote} segment of POST /api/ingesta/{lote}. Row keys of
 * each dataset are described in IngestaSink.
 */
enum Dataset: string
{
    use HasOptions;

    /** Open receivables per invoice (etl/queries/cartera.sql); consumed by Core. */
    case Receivables = 'cartera';

    /** SAP sales employees (OSLP, etl/queries/asesores.sql); consumed by Core. */
    case Advisors = 'asesores';

    /** Client contacts (OCRD and OCPR); consumed by Core. */
    case Contacts = 'contactos';

    /** Sales, for commissions; consumed by Comisiones. */
    case Sales = 'ventas';

    /** Payments received, for commissions; consumed by Comisiones. */
    case Collections = 'recaudos';

    /** Offsets between documents, for commissions; consumed by Comisiones. */
    case Offsets = 'cruces';

    /** Client master data, for commissions; consumed by Comisiones. */
    case CustomerMaster = 'maestro_clientes';

    /** Sales employee master data, for commissions; consumed by Comisiones. */
    case SalesRepMaster = 'maestro_vendedores';

    public function label(): string
    {
        return match ($this) {
            self::Receivables => 'Cartera',
            self::Advisors => 'Asesores',
            self::Contacts => 'Contactos',
            self::Sales => 'Ventas',
            self::Collections => 'Recaudos',
            self::Offsets => 'Cruces',
            self::CustomerMaster => 'Maestro de clientes',
            self::SalesRepMaster => 'Maestro de vendedores',
        };
    }
}
