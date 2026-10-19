<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;
use InvalidArgumentException;

/**
 * One version of a channel director's team (§4 "Equipo", §5.6): the director
 * earns $rate on the team's sales plus the team's collections, on top of
 * their own commission.
 */
final readonly class DirectorTeam
{
    /**
     * @param  string  $key  Stable key, e.g. "distribucion". Never a plain number: it is the
     *                       reference of the team's alerts and person role, next to the seller
     *                       codes ("14"), so it must not read as one.
     * @param  string  $channel  Channel name shown in reports, e.g. "Distribución".
     * @param  int|null  $associatedCode  The director's own seller code (53, 50), used when the
     *                                    director commission is placed on that code (open point 14.4).
     * @param  list<int>  $memberCodes  The team's seller codes, in report order.
     *
     * @throws InvalidArgumentException when the key is blank or a plain number, the channel is blank,
     *                                  or the members are empty or repeated.
     */
    public function __construct(
        public Validity $validity,
        public string $key,
        public string $channel,
        public PersonRef $director,
        public ?int $associatedCode,
        public Percentage $rate,
        public array $memberCodes,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A director team needs a key.');
        }

        if (ctype_digit(trim($key))) {
            throw new InvalidArgumentException("Director team key {$key} is a plain number; it would read as a seller code.");
        }

        if (trim($channel) === '') {
            throw new InvalidArgumentException("Director team {$key} needs a channel.");
        }

        if ($memberCodes === []) {
            throw new InvalidArgumentException("Director team {$key} needs at least one member code.");
        }

        if (count(array_unique($memberCodes)) !== count($memberCodes)) {
            throw new InvalidArgumentException("Director team {$key} lists a member code twice.");
        }
    }
}
