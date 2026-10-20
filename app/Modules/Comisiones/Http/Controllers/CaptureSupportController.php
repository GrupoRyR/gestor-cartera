<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Captures\CaptureKind;
use App\Modules\Comisiones\Infrastructure\Captures\Supports;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /comisiones/ajustes/{tipo}/{captura}/soporte (comisiones.ajustes.soporte,
 * comisiones.view): the support of a capture, streamed from the private
 * disk and audited as comisiones.support.downloaded (§10: downloads are
 * part of the trail).
 */
final class CaptureSupportController
{
    public function __invoke(Request $request, Supports $supports, AuditTrail $audit, string $tipo, string $captura): StreamedResponse
    {
        $kind = CaptureKind::from($tipo);
        $capture = $kind->find((int) $captura);

        abort_if($capture === null || ! $supports->exists($capture->soporte), 404);

        $audit->record(
            event: 'comisiones.support.downloaded',
            subjectType: $kind->value,
            subjectId: $capture->id,
            context: ['soporte' => basename((string) $capture->soporte)],
            period: $kind->periodOf($capture)?->key(),
        );

        return $supports->download((string) $capture->soporte, $kind, $capture->id);
    }
}
