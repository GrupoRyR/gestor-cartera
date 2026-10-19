<?php

namespace App\Modules\Asesores\Application\Agenda;

use App\Modules\Asesores\Domain\Agenda\Agenda;
use App\Modules\Core\Contracts\Data\Advisor;

/**
 * What the agenda screen shows.
 */
final readonly class AgendaPage
{
    /**
     * @param  list<Advisor>  $advisors  The advisors the user can look at.
     * @param  bool  $truncated  There are more open commitments than the screen reads.
     */
    public function __construct(
        public Agenda $agenda,
        public array $advisors,
        public bool $truncated,
    ) {}
}
