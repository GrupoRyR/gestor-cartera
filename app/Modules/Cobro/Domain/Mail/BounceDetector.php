<?php

namespace App\Modules\Cobro\Domain\Mail;

/**
 * Tells delivery failure reports (NDR, DSN) apart from human replies and
 * reads the reason and the failed address from them.
 *
 * A message is a bounce when it comes from a delivery system mailbox
 * (postmaster, mailer-daemon, Microsoft Exchange's system sender) or its
 * subject starts like the usual failure reports, in English or Spanish.
 * Auto-replies ("fuera de la oficina"), delay warnings and delivery
 * receipts are not bounces: they stay replies.
 */
final class BounceDetector
{
    /**
     * Local parts of delivery system senders.
     */
    private const string SYSTEM_SENDER = '/^(postmaster|mailer-daemon|mail-daemon|microsoftexchange[0-9a-f]*|mail\.?delivery\.?(subsystem|system)?)@/i';

    private const string FAILURE_SUBJECT = '/^\s*(undeliverable|undelivered mail returned to sender|delivery status notification \(failure\)|mail delivery failed|returned mail|failure notice|delivery failure|message not delivered|no se puede entregar|no se pudo entregar|no entregable|mensaje no entregado|error de entrega|notificaci[oó]n de estado de entrega \(error\))(?!\w)/iu';

    /**
     * Reports from the same system senders that are not failures: delivery
     * still being retried, or delivered. The mail may still arrive, so they
     * never mark a send as bounced.
     */
    private const string NOT_A_FAILURE_SUBJECT = '/^\s*(delivery delayed|delivery status notification \(delay\)|delayed mail|warning: message .* delayed|entrega retrasada|retraso en la entrega|delivered|relayed|entregado|retransmitido)(?!\w)/iu';

    /**
     * SMTP enhanced status codes (RFC 3463) of a permanent or transient failure.
     */
    private const string STATUS_CODE = '/\b[45]\.\d{1,3}\.\d{1,3}\b/';

    /**
     * Sentences that explain the failure when there is no status code.
     */
    private const string REASON_SENTENCE = '/^.*(couldn\'t be delivered|could not be delivered|wasn\'t found|was not found|does not exist|doesn\'t exist|mailbox (is )?(full|unavailable)|quota|rejected|no se pudo entregar|no existe|no se encontr[oó]|buz[oó]n lleno|rechaz).*$/imu';

    private const string EMAIL = '/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    public function isBounce(string $from, string $subject): bool
    {
        if (preg_match(self::NOT_A_FAILURE_SUBJECT, $subject) === 1) {
            return false;
        }

        return preg_match(self::SYSTEM_SENDER, trim($from)) === 1
            || preg_match(self::FAILURE_SUBJECT, $subject) === 1;
    }

    /**
     * The reason and failed address of a bounce.
     *
     * @param  string  $body  Plain-text body of the report.
     * @param  list<string>  $recipients  Addresses of the original mail, when known (To before Cc):
     *                                    the failed address is the first of them, in this order,
     *                                    that the report names.
     * @param  list<string>  $ignore  Addresses that are never the failed one (the company mailbox).
     */
    public function read(string $body, string $preview = '', array $recipients = [], array $ignore = []): Bounce
    {
        return new Bounce(
            self::reason($body, $preview),
            self::recipient($body, $recipients, $ignore),
        );
    }

    private static function reason(string $body, string $preview): string
    {
        $lines = array_values(array_filter(
            array_map(fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)), preg_split('/\R/u', $body) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        foreach ($lines as $line) {
            if (preg_match(self::STATUS_CODE, $line) === 1 && preg_match('/^(message-id|date|received|x-[\w-]+|content-[\w-]+)\s*:/i', $line) !== 1) {
                return self::clip($line);
            }
        }

        foreach ($lines as $line) {
            if (preg_match(self::REASON_SENTENCE, $line) === 1) {
                return self::clip($line);
            }
        }

        $preview = trim((string) preg_replace('/\s+/u', ' ', $preview));

        return self::clip($preview !== '' ? $preview : ($lines[0] ?? 'Sin motivo en el informe de rebote.'));
    }

    /**
     * @param  list<string>  $recipients
     * @param  list<string>  $ignore
     */
    private static function recipient(string $body, array $recipients, array $ignore): ?string
    {
        preg_match_all(self::EMAIL, $body, $matches);
        $found = array_values(array_unique(array_map('mb_strtolower', $matches[0])));

        $known = array_map(fn (string $address): string => mb_strtolower(trim($address)), $recipients);

        // The original recipients in their stored order (To before Cc),
        // not the order the report names them in: a report quoting the
        // original headers names the copies too.
        foreach ($known as $address) {
            if (in_array($address, $found, true)) {
                return $address;
            }
        }

        if ($known !== []) {
            return null;
        }

        $ignored = array_map(fn (string $address): string => mb_strtolower(trim($address)), $ignore);

        foreach ($found as $address) {
            if (! in_array($address, $ignored, true) && preg_match(self::SYSTEM_SENDER, $address) !== 1) {
                return $address;
            }
        }

        return null;
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 299)).'…' : $text;
    }
}
