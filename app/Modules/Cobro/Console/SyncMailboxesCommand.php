<?php

namespace App\Modules\Cobro\Console;

use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Mail\CompanyMailboxes;
use App\Modules\Cobro\Infrastructure\Mail\MailboxSync;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * cobro:sincronizar-buzones: reads the new replies and bounces of every
 * company inbox (MailboxSync). CobroServiceProvider runs it every five
 * minutes. Without Graph credentials it only warns and succeeds, so a
 * local copy does not fail every five minutes; a Graph error in one inbox
 * does not stop the others and makes the command fail.
 */
final class SyncMailboxesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cobro:sincronizar-buzones';

    /**
     * @var string
     */
    protected $description = 'Trae las respuestas y los rebotes nuevos de los buzones de cobro';

    public function handle(MailboxSync $sync, CompanyMailboxes $mailboxes): int
    {
        if (! GraphClient::configured()) {
            $this->warn(__('cobro::respuestas.consola.sin_graph'));

            return self::SUCCESS;
        }

        $failed = false;

        foreach (array_keys($mailboxes->all()) as $code) {
            $company = CompanyCode::from($code);

            try {
                $stored = $sync->sync($company);
                $this->info(__('cobro::respuestas.consola.sincronizado', ['empresa' => $company->label(), 'nuevos' => $stored]));
            } catch (GraphRequestFailed $e) {
                Log::error('Cobro: a company inbox could not be synchronized.', ['company' => $code, 'exception' => $e->getMessage()]);
                $this->error(__('cobro::respuestas.consola.fallo', ['empresa' => $company->label()]));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
