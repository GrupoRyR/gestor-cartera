<?php

namespace App\Modules\Cobro\Console;

use App\Modules\Cobro\Domain\Mail\BounceDetector;
use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Mail\CompanyMailboxes;
use App\Modules\Cobro\Infrastructure\Mail\MailboxSync;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Console\Command;
use UnexpectedValueException;

/**
 * cobro:diagnosticar-rebote: shows what the bounce rule (audit S-24) reads
 * from one message of a company inbox and what it decides, so IT can
 * check it against a real Exchange report before relying on it. With
 * --probar-delta it also asks Graph for one page of the inbox delta query
 * with the message class, to confirm Graph accepts the $expand. The domains
 * it prints as ours include cobro.tenant_domains.
 *
 * Read-only: it never prints the body or the subject, never writes to the
 * database and never moves the sync cursors.
 */
final class DiagnoseBounceCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cobro:diagnosticar-rebote
        {buzon : SGX o SDC}
        {mensaje? : Id de Graph del mensaje}
        {--probar-delta : Prueba una vez la consulta delta con la clase del mensaje (solo la primera página, de un mensaje)}';

    /**
     * @var string
     */
    protected $description = 'Muestra cómo la regla de rebotes clasifica un mensaje del buzón, sin guardar nada';

    public function handle(MailboxSync $sync, CompanyMailboxes $mailboxes, GraphClient $graph, BounceDetector $bounces): int
    {
        $company = CompanyCode::tryFrom(mb_strtoupper(trim((string) $this->argument('buzon'))));
        $messageId = trim((string) $this->argument('mensaje'));
        $probe = (bool) $this->option('probar-delta');

        if ($company === null) {
            $this->error('El buzón debe ser SGX o SDC.');

            return self::FAILURE;
        }

        if ($messageId === '' && ! $probe) {
            $this->error('Indica el id de Graph del mensaje o usa --probar-delta.');

            return self::FAILURE;
        }

        try {
            $mailbox = $mailboxes->for($company);
        } catch (UnexpectedValueException) {
            $this->error("No hay un buzón configurado para {$company->label()}.");

            return self::FAILURE;
        }

        $this->line("Buzón: {$mailbox}");
        $ok = true;

        if ($probe) {
            $ok = $this->probeDelta($graph, $mailbox);
        }

        if ($messageId !== '') {
            $ok = $this->diagnose($graph, $sync, $bounces, $mailbox, $messageId) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function probeDelta(GraphClient $graph, string $mailbox): bool
    {
        try {
            // Always with CLASS_EXPAND and no fallback, unlike the sync, so
            // a refusal shows here with its status.
            $graph->get(MailboxSync::deltaPath($mailbox), MailboxSync::initialQuery(), MailboxSync::preferHeader(1));
        } catch (GraphRequestFailed $e) {
            $this->error('Graph rechazó la consulta delta con la clase del mensaje: '.self::status($e).'.');

            return false;
        }

        $this->info('Graph aceptó la consulta delta con la clase del mensaje (HTTP 2xx).');

        return true;
    }

    private function diagnose(GraphClient $graph, MailboxSync $sync, BounceDetector $bounces, string $mailbox, string $messageId): bool
    {
        try {
            $message = $graph->get(GraphClient::mailbox($mailbox).'/messages/'.rawurlencode($messageId), [
                '$select' => 'from,internetMessageHeaders',
                '$expand' => MailboxSync::CLASS_EXPAND,
            ]);
        } catch (GraphRequestFailed $e) {
            $this->error('Graph no devolvió el mensaje: '.self::status($e).'.');

            return false;
        }

        $from = data_get($message, 'from.emailAddress.address');
        $class = MailboxSync::messageClass($message);
        $authAs = MailboxSync::header($message, MailboxSync::AUTH_AS_HEADER);
        $contentType = (string) MailboxSync::header($message, 'Content-Type');
        $envelope = $sync->envelope(is_string($from) ? $from : '', $class, $authAs);

        $this->line('Clase del mensaje: '.($class ?? '(sin dato)'));
        $this->line(MailboxSync::AUTH_AS_HEADER.': '.($authAs ?? '(sin dato)'));
        $this->line('Remitente: '.($envelope->sender === '' ? '(sin dato)' : $envelope->sender));
        $this->line('Estructura DSN (multipart/report): '.(stripos($contentType, 'multipart/report') !== false ? 'sí' : 'no'));
        $this->line('Dominios propios: '.implode(', ', $envelope->ownDomains));
        $this->info('Decisión: '.match ($bounces->classify($envelope)) {
            BounceDetector::OWN_REPORT => 'rebote de nuestro Exchange; marca el envío como «Rebotado».',
            BounceDetector::EXTERNAL_REPORT => 'rebote de otro servidor, solo si cita el Message-ID de un envío nuestro; si no, respuesta normal.',
            default => 'respuesta normal; no marca ningún envío.',
        });

        return true;
    }

    private static function status(GraphRequestFailed $e): string
    {
        return $e->status === null ? 'sin respuesta' : "HTTP {$e->status}";
    }
}
