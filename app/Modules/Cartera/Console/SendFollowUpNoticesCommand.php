<?php

namespace App\Modules\Cartera\Console;

use App\Modules\Cartera\Application\Calls\SendFollowUpNotices;
use Illuminate\Console\Command;

/**
 * cartera:avisos-seguimiento: rings the bell of whoever logged a call on
 * the day they agreed to call the client again (SendFollowUpNotices).
 * CarteraServiceProvider schedules it every hour from 07:00 to 18:00 in
 * Bogotá; running it again by hand is safe. It fails when a notice could
 * not be sent, so the scheduler records it.
 */
final class SendFollowUpNoticesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cartera:avisos-seguimiento';

    /**
     * @var string
     */
    protected $description = 'Avisa en la campana los seguimientos de llamadas que tocan hoy';

    public function handle(SendFollowUpNotices $task): int
    {
        ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed] = $task->run();

        $this->info(self::text('enviados', $sent));

        if ($skipped > 0) {
            $this->warn(self::text('omitidos', $skipped));
        }

        if ($failed === 0) {
            return self::SUCCESS;
        }

        $this->error(self::text('fallidos', $failed));

        return self::FAILURE;
    }

    private static function text(string $key, int $count): string
    {
        return trans_choice("cartera::llamadas.consola.{$key}", $count, ['total' => $count]);
    }
}
