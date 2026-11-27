<?php

namespace App\Modules\Cartera\Application\Calls;

use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Support\Formatting\DateFormat;

/**
 * The bell notice of a call follow-up that is due: «Agro Arango SAS: hoy
 * toca volver a llamar», with «Lo acordaste en la llamada del 10/11/2026
 * con Juan Pérez.» as its body. It goes to whoever logged the call.
 *
 * Its key is the one LogCall resolves (LogCall::followUpKey()), so the next
 * call logged on the client closes it. A follow-up whose day already passed
 * (the server was down that day) keeps the same title.
 */
final class FollowUpNotice
{
    public const string KIND = 'cartera.call.follow_up';

    /** Length of notices.title. */
    private const int TITLE_LENGTH = 160;

    public static function for(CallRecord $call, string $clientName): Aviso
    {
        $date = DateFormat::date($call->occurred_at);

        return new Aviso(
            recipients: [$call->user_id],
            kind: self::KIND,
            title: self::title($clientName),
            body: $call->contact_name === null
                ? self::text('aviso_seguimiento.cuerpo', ['fecha' => $date])
                : self::text('aviso_seguimiento.cuerpo_con_contacto', ['fecha' => $date, 'contacto' => $call->contact_name]),
            company: $call->company->value,
            subjectType: 'client',
            subjectId: $call->client_id,
            key: LogCall::followUpKey($call->client_id),
            data: ['call_id' => $call->id],
        );
    }

    /**
     * The title, with the client's name shortened when it would not fit.
     */
    private static function title(string $client): string
    {
        $title = self::text('aviso_seguimiento.titulo', ['cliente' => $client]);
        $excess = mb_strlen($title) - self::TITLE_LENGTH;

        if ($excess <= 0) {
            return $title;
        }

        $client = rtrim(mb_substr($client, 0, max(1, mb_strlen($client) - $excess - 1))).'…';

        return self::text('aviso_seguimiento.titulo', ['cliente' => $client]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace): string
    {
        $text = __("cartera::llamadas.{$key}", $replace);

        return is_string($text) ? $text : $key;
    }
}
