<?php

namespace App\Modules\Cobro\Infrastructure\Graph;

use RuntimeException;
use Throwable;

/**
 * Microsoft Graph (or its token endpoint) refused a request, did not answer
 * or is not configured. The message names the operation, the HTTP status and
 * the Graph error code; it never carries tokens, secrets or mail content.
 */
final class GraphRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $graphCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('Microsoft Graph is not configured: set GRAPH_TENANT_ID, GRAPH_CLIENT_ID and GRAPH_CLIENT_SECRET.');
    }
}
