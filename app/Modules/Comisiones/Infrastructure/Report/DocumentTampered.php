<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Infrastructure\Persistence\DocumentRecord;
use RuntimeException;

/**
 * A stored report whose file is missing or no longer matches its sha256
 * (§10): it is never handed out, nor generated again over the stored one.
 */
final class DocumentTampered extends RuntimeException
{
    public function __construct(
        public readonly DocumentRecord $document,
        string $reason,
    ) {
        parent::__construct(sprintf(
            'The stored commission report #%d (%s %s, %s) %s.',
            $document->id,
            $document->tipo,
            $document->referencia,
            $document->ruta,
            $reason,
        ));
    }
}
