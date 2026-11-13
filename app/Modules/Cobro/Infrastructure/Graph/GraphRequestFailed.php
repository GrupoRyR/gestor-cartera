<?php

namespace App\Modules\Cobro\Infrastructure\Graph;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use RuntimeException;
use Throwable;

/**
 * Microsoft Graph (or its token endpoint) refused a request, did not answer
 * or is not configured. The message names the operation, the HTTP status and
 * the Graph error code; it never carries tokens, secrets or mail content.
 */
final class GraphRequestFailed extends RuntimeException
{
    /**
     * $graphCode of notConfigured().
     */
    public const string NOT_CONFIGURED = 'not_configured';

    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $graphCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Neither the credential of $company nor the default one is complete.
     * The message names the variables to set, never their values.
     */
    public static function notConfigured(CompanyCode $company): self
    {
        $code = $company->value;

        return new self(
            "Microsoft Graph is not configured for {$code}: set GRAPH_TENANT_ID, GRAPH_CLIENT_ID and GRAPH_CLIENT_SECRET, "
            ."or GRAPH_{$code}_TENANT_ID, GRAPH_{$code}_CLIENT_ID and GRAPH_{$code}_CLIENT_SECRET.",
            graphCode: self::NOT_CONFIGURED,
        );
    }
}
