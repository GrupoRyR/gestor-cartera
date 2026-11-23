<?php

namespace App\Modules\Cobro\Console;

use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphCredentials;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Mail\CompanyMailboxes;
use App\Modules\Cobro\Infrastructure\Mail\MailboxSync;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * cobro:sincronizar-buzones: reads the new replies and bounces of every
 * company inbox (MailboxSync). CobroServiceProvider runs it every five
 * minutes. A company without a usable Graph credential (GraphCredentials)
 * is skipped and does not make the command fail, so a local copy or a
 * company whose app registration is not ready yet does not fail every five
 * minutes and the other company still syncs: the console says so on every
 * run, the log at most once a day per company. A Graph error in one inbox
 * does not stop the others and makes the command fail.
 */
final class SyncMailboxesCommand extends Command
{
    /**
     * Cache key prefix of the day a company's skip was last logged.
     */
    private const string SKIP_LOGGED_KEY = 'cobro.sync.skip_logged.';

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
        $failed = false;

        foreach (array_keys($mailboxes->all()) as $code) {
            $company = CompanyCode::from($code);

            if (! GraphClient::configured($company)) {
                $this->skip($company);

                continue;
            }

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

    /**
     * Warns that $company's inbox is skipped: on the console every run, in
     * the log only on the first run of the day.
     */
    private function skip(CompanyCode $company): void
    {
        $message = GraphCredentials::usesDefault($company) ? 'sin_graph' : 'sin_graph_propia';
        $this->warn(__("cobro::respuestas.consola.{$message}", ['empresa' => $company->label(), 'codigo' => $company->value]));

        $today = now()->toDateString();

        if (Cache::add(self::SKIP_LOGGED_KEY.$company->value.'.'.$today, true, now()->endOfDay())) {
            Log::warning('Cobro: a company inbox is skipped because it has no Graph credential.', ['company' => $company->value]);
        }
    }
}
