<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Application\Commitments\BreakOverdueCommitments;
use Illuminate\Console\Command;

/**
 * core:compromisos-incumplidos: marks as broken the pending commitments due
 * before today (Bogotá). CoreServiceProvider schedules it every day at
 * 00:15; running it again by hand is safe.
 */
final class BreakOverdueCommitmentsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'core:compromisos-incumplidos';

    /**
     * @var string
     */
    protected $description = 'Marca como incumplidos los compromisos de pago pendientes que vencieron antes de hoy';

    public function handle(BreakOverdueCommitments $task): int
    {
        ['broken' => $broken, 'failed' => $failed] = $task->run();

        $this->info(self::text('core::compromisos.consola.incumplidos', $broken));

        if ($failed === 0) {
            return self::SUCCESS;
        }

        $this->error(self::text('core::compromisos.consola.fallidos', $failed));

        return self::FAILURE;
    }

    private static function text(string $key, int $count): string
    {
        return trans_choice($key, $count, ['total' => $count]);
    }
}
