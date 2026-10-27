<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Infrastructure\Audit\AuditChain;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * core:verificar-auditoria: walks "audit_logs" by id and recomputes every
 * link of the hash chain (AuditChain, audit S-14). It fails on a row without
 * a hash, a hash that does not follow from the row and its predecessor (a
 * row changed, or one deleted or inserted before it), a head that does not
 * point to the last row, or a checkpoint mismatch. CoreServiceProvider
 * schedules it daily; running it by hand is safe.
 *
 * The checkpoint is a JSON file on the local disk, outside the database,
 * with the last id and hash of the previous successful run: if that row is
 * gone or its hash changed, the tail was cut or rewritten, even with the
 * head rewound to match. A failed run keeps the old checkpoint.
 *
 * On failure it logs a critical entry and mails core.audit.alert_recipients
 * through MailSender (category "alerta"), with ids, counts and the reason
 * only, never the contents of an entry. It fails with or without recipients.
 */
final class VerifyAuditChainCommand extends Command
{
    /**
     * The checkpoint, on the "local" disk (storage/app/private).
     */
    public const string CHECKPOINT = 'auditoria/cadena.json';

    public const string TIMEZONE = 'America/Bogota';

    private const int CHUNK = 1000;

    /**
     * @var string
     */
    protected $signature = 'core:verificar-auditoria';

    /**
     * @var string
     */
    protected $description = 'Verifica la cadena de hashes de la auditoría y avisa por correo si alguien la alteró';

    public function handle(AuditChain $chain): int
    {
        $checkpoint = $this->checkpoint();

        if ($checkpoint === false) {
            return $this->failed(null, 'checkpoint_unreadable', 0);
        }

        // The highest id before the head: an append that commits in between
        // only moves the head past it, so the walk stops where the head was.
        $maxId = DB::table('audit_logs')->max('id');
        $head = $chain->head() ?? ['last_id' => null, 'last_hash' => null];
        $upTo = max(is_numeric($maxId) ? (int) $maxId : 0, $head['last_id'] ?? 0);

        $previous = null;
        $lastId = null;
        $checked = 0;
        $checkpointSeen = false;

        foreach (DB::table('audit_logs')->where('id', '<=', $upTo)->lazyById(self::CHUNK) as $row) {
            $row = (array) $row;
            $id = (int) $row['id'];
            $hash = is_string($row['chain_hash'] ?? null) ? $row['chain_hash'] : null;

            if ($hash === null) {
                return $this->failed($id, 'missing_hash', $checked);
            }

            if (! $chain->matches($previous, $row, $hash)) {
                return $this->failed($id, 'hash_mismatch', $checked);
            }

            if ($checkpoint !== null && $id === $checkpoint['last_id']) {
                if (! hash_equals((string) $checkpoint['last_hash'], $hash)) {
                    return $this->failed($id, 'checkpoint_mismatch', $checked);
                }

                $checkpointSeen = true;
            }

            $previous = $hash;
            $lastId = $id;
            $checked++;
        }

        if ($lastId !== $head['last_id'] || $previous !== $head['last_hash']) {
            return $this->failed($head['last_id'] ?? $lastId, 'head_mismatch', $checked);
        }

        if ($checkpoint !== null && $checkpoint['last_id'] !== null && ! $checkpointSeen) {
            return $this->failed($checkpoint['last_id'], 'checkpoint_mismatch', $checked);
        }

        Storage::disk('local')->put(self::CHECKPOINT, (string) json_encode(['last_id' => $lastId, 'last_hash' => $previous]));

        $this->info(trans_choice('core::auditoria.consola.integra', $checked, ['total' => $checked, 'id' => $lastId]));

        return self::SUCCESS;
    }

    /**
     * The checkpoint of the last successful run: null before the first one,
     * false when the file is not what this command writes.
     *
     * @return array{last_id: int|null, last_hash: string|null}|false|null
     */
    private function checkpoint(): array|false|null
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::CHECKPOINT)) {
            return null;
        }

        $values = json_decode((string) $disk->get(self::CHECKPOINT), true);

        if (! is_array($values)
            || ! array_key_exists('last_id', $values) || ! (is_int($values['last_id']) || $values['last_id'] === null)
            || ! array_key_exists('last_hash', $values) || ! (is_string($values['last_hash']) || $values['last_hash'] === null)) {
            return false;
        }

        return ['last_id' => $values['last_id'], 'last_hash' => $values['last_hash']];
    }

    private function failed(?int $id, string $reason, int $checked): int
    {
        Log::critical('Core: the audit chain is broken.', ['first_bad_id' => $id, 'reason' => $reason, 'checked' => $checked]);

        $motive = __("core::auditoria.motivos.{$reason}");
        $this->error($id === null
            ? __('core::auditoria.consola.fallo_sin_entrada', ['motivo' => $motive])
            : __('core::auditoria.consola.fallo', ['id' => $id, 'motivo' => $motive]));

        $this->sendAlert($id, $reason, $checked);

        return self::FAILURE;
    }

    private function sendAlert(?int $id, string $reason, int $checked): void
    {
        /** @var list<string> $recipients */
        $recipients = (array) config('core.audit.alert_recipients', []);

        if ($recipients === []) {
            Log::warning('Core: the audit chain is broken and no alert recipients are configured.');
            $this->warn(__('core::auditoria.consola.sin_destinatarios'));

            return;
        }

        if (! app()->bound(MailSender::class)) {
            Log::error('Core: the audit chain alert could not be sent: MailSender has no implementation.');
            $this->error(__('core::auditoria.consola.sin_mail_sender'));

            return;
        }

        $at = CarbonImmutable::now(self::TIMEZONE);

        try {
            // The platform's alert goes out from the Segurex mailbox, like IT's other notices.
            app(MailSender::class)->send(new OutgoingMail(
                company: CompanyCode::SGX,
                to: $recipients,
                subject: __('core::auditoria.asunto', ['fecha' => DateFormat::date($at)]),
                html: view('core::auditoria.alerta', ['at' => $at, 'id' => $id, 'reason' => $reason, 'checked' => $checked])->render(),
                category: 'alerta',
            ));
        } catch (MailNotSent $e) {
            Log::error('Core: the audit chain alert could not be sent.', ['exception' => $e]);
            $this->error(__('core::auditoria.consola.no_enviada'));

            return;
        }

        $this->warn(__('core::auditoria.consola.enviada', ['destinatarios' => implode(', ', $recipients)]));
    }
}
