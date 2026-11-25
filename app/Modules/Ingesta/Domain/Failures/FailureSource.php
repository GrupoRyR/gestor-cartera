<?php

namespace App\Modules\Ingesta\Domain\Failures;

/**
 * Who reported a load failure. The robot never sends it: the report
 * endpoint sets it.
 */
enum FailureSource: string
{
    /** The ETL robot (etl/), through POST /api/ingesta/reportes. */
    case Robot = 'robot';

    /** The Bases SAP reader, in process. */
    case BasesSap = 'bases_sap';
}
