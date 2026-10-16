<?php

namespace App\Modules\Core\Application\Activity;

use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * The one-line summary of an ActivityType::StatusChange entry in a client's
 * log, with the Spanish labels of both statuses (core::bitacora.resumen).
 */
final class StatusChangeSummary
{
    /**
     * «Estado automático: de «Vencida» a «Por vencer»»: a snapshot changed it.
     */
    public static function automatic(ClientStatus $from, ClientStatus $to): string
    {
        return self::text('core::bitacora.resumen.estado_automatico', $from, $to);
    }

    /**
     * «De «Vencida» a «Jurídico»»: a user changed it (ClientStatusService).
     */
    public static function manual(ClientStatus $from, ClientStatus $to): string
    {
        return self::text('core::bitacora.resumen.estado_manual', $from, $to);
    }

    private static function text(string $key, ClientStatus $from, ClientStatus $to): string
    {
        $text = __($key, ['anterior' => $from->label(), 'nuevo' => $to->label()]);

        return is_string($text) ? $text : $key;
    }
}
