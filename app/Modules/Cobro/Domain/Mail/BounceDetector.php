<?php

namespace App\Modules\Cobro\Domain\Mail;

/**
 * Tells delivery failure reports (NDR, DSN) apart from human replies and
 * reads the reason and the failed address from them (audit S-24).
 *
 * The sender and the subject alone prove nothing: anyone can write
 * "Undeliverable:" from any address. A message is a failure report only
 * when Exchange gave it a REPORT.*.NDR message class, which it computes
 * from a real multipart/report with a message/delivery-status part, and
 * then only when it is (classify()):
 *
 * - OWN_REPORT: our Exchange's own report: authenticated as Internal
 *   (X-MS-Exchange-Organization-AuthAs, which Exchange strips from mail
 *   entering from outside), from postmaster or Exchange's system sender
 *   (MicrosoftExchange + 32 hex digits) at one of our domains (the company
 *   mailboxes' or the Microsoft 365 tenant's);
 * - EXTERNAL_REPORT: another server's report, from a delivery system
 *   mailbox of any domain. It is a bounce only if it quotes the
 *   Message-ID of one of our sends; the caller checks that.
 *
 * Everything else is a REPLY: an "Undeliverable:" subject on an IPM.Note,
 * delay reports (*.Delayed.DR), delivery receipts (*.DR), auto-replies and
 * plain-text reports without the DSN structure.
 */
final class BounceDetector
{
    public const string OWN_REPORT = 'own_report';

    public const string EXTERNAL_REPORT = 'external_report';

    public const string REPLY = 'reply';

    /**
     * Local parts of delivery system senders.
     */
    private const string SYSTEM_SENDER = '/^(postmaster|mailer-daemon|mail-daemon|microsoftexchange[0-9a-f]*|mail\.?delivery\.?(subsystem|system)?)@/i';

    /**
     * Message class of a non-delivery report; delay reports end in
     * ".Delayed.DR" and delivery receipts in ".DR".
     */
    private const string FAILURE_CLASS = '/^REPORT\..+\.NDR$/i';

    /**
     * Local parts of our Exchange's own report senders.
     */
    private const string OWN_REPORT_SENDER = '/^(postmaster|microsoftexchange[0-9a-f]{32})$/i';

    /**
     * Local parts of other servers' report senders.
     */
    private const string EXTERNAL_REPORT_SENDER = '/^(postmaster|mailer-daemon|mail-daemon|mail\.?delivery\.?(subsystem|system)?)$/i';

    /**
     * SMTP enhanced status codes (RFC 3463) of a permanent or transient failure.
     */
    private const string STATUS_CODE = '/\b[45]\.\d{1,3}\.\d{1,3}\b/';

    /**
     * Sentences that explain the failure when there is no status code.
     */
    private const string REASON_SENTENCE = '/^.*(couldn\'t be delivered|could not be delivered|wasn\'t found|was not found|does not exist|doesn\'t exist|mailbox (is )?(full|unavailable)|quota|rejected|no se pudo entregar|no existe|no se encontr[oó]|buz[oó]n lleno|rechaz).*$/imu';

    private const string EMAIL = '/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    /**
     * OWN_REPORT, EXTERNAL_REPORT or REPLY.
     */
    public function classify(InboundEnvelope $envelope): string
    {
        if (! self::isFailureClass($envelope->messageClass)) {
            return self::REPLY;
        }

        $local = $envelope->localPart();

        if ($envelope->internal() && $envelope->fromOwnDomain() && preg_match(self::OWN_REPORT_SENDER, $local) === 1) {
            return self::OWN_REPORT;
        }

        return $envelope->domain() !== '' && preg_match(self::EXTERNAL_REPORT_SENDER, $local) === 1
            ? self::EXTERNAL_REPORT
            : self::REPLY;
    }

    /**
     * Whether a message class is a non-delivery report's.
     */
    public static function isFailureClass(?string $class): bool
    {
        return $class !== null && preg_match(self::FAILURE_CLASS, trim($class)) === 1;
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
