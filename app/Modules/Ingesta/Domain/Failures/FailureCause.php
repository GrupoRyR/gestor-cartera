<?php

namespace App\Modules\Ingesta\Domain\Failures;

/**
 * Why a load failed, as the reporter classified it.
 */
enum FailureCause: string
{
    /** The VPN to SAP did not connect or dropped. */
    case Vpn = 'vpn';

    /** The VPN is up but there is no route to the HANA server. */
    case HanaRoute = 'ruta_hana';

    /** Any other network error, like a timeout or a refused connection. */
    case Network = 'red';

    /** A user, password or key was refused. */
    case Credentials = 'credenciales';

    /** The extracted data did not pass validation or the contract. */
    case Data = 'datos';

    /** A setting is missing or wrong. */
    case Configuration = 'configuracion';

    /** None of the above. */
    case Unexpected = 'inesperado';
}
