<?php

namespace App\Modules\Ingesta\Domain\Failures;

/**
 * The stage a load failed in, with the exact stage names of etl/run_etl.py.
 */
enum FailureStage: string
{
    /** Reading the robot settings. */
    case Configuration = 'CONFIGURACION';

    /** Starting the run. */
    case Start = 'INICIO';

    /** Connecting the VPN. */
    case Vpn = 'VPN';

    /** Querying SAP HANA. */
    case Hana = 'HANA';

    /** Validating the extracted rows. */
    case Validation = 'VALIDACION';

    /** Writing the CSV backup. */
    case Backup = 'RESPALDO';

    /** Checking the rows against the shared schema. */
    case Contract = 'CONTRATO';

    /** Publishing the batch to this app. */
    case Laravel = 'LARAVEL';

    /** Publishing to Supabase. */
    case Supabase = 'SUPABASE';
}
