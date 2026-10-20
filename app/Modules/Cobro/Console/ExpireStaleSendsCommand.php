<?php

namespace App\Modules\Cobro\Console;

use App\Modules\Cobro\Infrastructure\Runs\RunFinisher;
use Illuminate\Console\Command;

/**
 * cobro:cerrar-envios-interrumpidos: marks as not sent the clients a
 * killed queue worker left "enviando" for more than
 * RunFinisher::STALE_MINUTES, so their runs close and the report shows
 * them. CobroServiceProvider runs it every ten minutes.
 */
final class ExpireStaleSendsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cobro:cerrar-envios-interrumpidos';

    /**
     * @var string
     */
    protected $description = 'Cierra los envíos de cobro que un proceso interrumpido dejó a medias';

    public function handle(RunFinisher $finisher): int
    {
        $closed = $finisher->expireStale();

        $this->info(trans_choice('cobro::envios.consola.interrumpidos', $closed, ['total' => $closed]));

        return self::SUCCESS;
    }
}
