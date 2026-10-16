<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Period;
use Closure;
use InvalidArgumentException;

/**
 * Every version of the commission parameters (§4: rates, tranches, teams and
 * derived commissions are editable with validity; §14 options too).
 *
 * Versions of the same key never share a day. A period takes, for each key,
 * the version with the latest start among those in force on at least one of
 * its days, so a change from the 16th applies to that whole month.
 */
final readonly class ParameterBook
{
    /**
     * @param  list<SellerCodeRule>  $codes  Keyed by code number.
     * @param  list<TrancheTable>  $trancheTables  A single key: the tranche table.
     * @param  list<DirectorTeam>  $teams  Keyed by team key.
     * @param  list<DerivedCommissionRule>  $derived  Keyed by derived key ("81.1").
     * @param  list<OptionsVersion>  $options  A single key; none in force means the defaults.
     *
     * @throws InvalidArgumentException when two versions of the same key overlap.
     */
    public function __construct(
        public array $codes,
        public array $trancheTables,
        public array $teams,
        public array $derived,
        public array $options = [],
    ) {
        self::assertNoOverlap($codes, self::codeKey(...));
        self::assertNoOverlap($trancheTables, self::trancheKey(...));
        self::assertNoOverlap($teams, self::teamKey(...));
        self::assertNoOverlap($derived, self::derivedKey(...));
        self::assertNoOverlap($options, self::optionsKey(...));
    }

    /**
     * The parameters in force for the period.
     *
     * @throws MissingParameters when no tranche table is in force.
     */
    public function forPeriod(Period $period): PeriodParameters
    {
        $trancheTables = self::inForce($this->trancheTables, $period, self::trancheKey(...));

        if ($trancheTables === []) {
            throw MissingParameters::trancheTable($period);
        }

        $options = self::inForce($this->options, $period, self::optionsKey(...));

        return new PeriodParameters(
            $period,
            self::inForce($this->codes, $period, self::codeKey(...)),
            $trancheTables[0],
            self::inForce($this->teams, $period, self::teamKey(...)),
            self::inForce($this->derived, $period, self::derivedKey(...)),
            $options === [] ? new SettlementOptions : $options[0]->options,
        );
    }

    /**
     * For each key, the version in force in the period with the latest start,
     * in the order each key first appears.
     *
     * @template T of SellerCodeRule|TrancheTable|DirectorTeam|DerivedCommissionRule|OptionsVersion
     *
     * @param  list<T>  $versions
     * @param  Closure(T): string  $key
     * @return list<T>
     */
    private static function inForce(array $versions, Period $period, Closure $key): array
    {
        $latest = [];

        foreach ($versions as $version) {
            if (! $version->validity->overlaps($period)) {
                continue;
            }

            $versionKey = $key($version);
            $current = $latest[$versionKey] ?? null;

            if ($current === null || $version->validity->from > $current->validity->from) {
                $latest[$versionKey] = $version;
            }
        }

        return array_values($latest);
    }

    /**
     * @template T of SellerCodeRule|TrancheTable|DirectorTeam|DerivedCommissionRule|OptionsVersion
     *
     * @param  list<T>  $versions
     * @param  Closure(T): string  $key
     *
     * @throws InvalidArgumentException when two versions of the same key share a day.
     */
    private static function assertNoOverlap(array $versions, Closure $key): void
    {
        $seen = [];

        foreach ($versions as $version) {
            $versionKey = $key($version);

            foreach ($seen[$versionKey] ?? [] as $other) {
                if ($version->validity->overlapsValidity($other->validity)) {
                    throw new InvalidArgumentException(sprintf(
                        'Two versions of %s are in force on the same day (from %s and from %s).',
                        $versionKey,
                        $other->validity->from->format('Y-m-d'),
                        $version->validity->from->format('Y-m-d'),
                    ));
                }
            }

            $seen[$versionKey][] = $version;
        }
    }

    private static function codeKey(SellerCodeRule $rule): string
    {
        return "code {$rule->code}";
    }

    private static function trancheKey(TrancheTable $table): string
    {
        return 'the tranche table';
    }

    private static function teamKey(DirectorTeam $team): string
    {
        return "team {$team->key}";
    }

    private static function derivedKey(DerivedCommissionRule $rule): string
    {
        return "derived commission {$rule->key}";
    }

    private static function optionsKey(OptionsVersion $version): string
    {
        return 'the settlement options';
    }
}
