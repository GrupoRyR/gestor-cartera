<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use InvalidArgumentException;

/**
 * Everything one calculation produced for a period (§5, §8.1, §9): the
 * settlement of every code, the derived and director commissions, the
 * totals per person, the alerts and the controls, with the parameters it
 * used frozen alongside.
 */
final readonly class SettlementResult
{
    /** @var array<int, CodeSettlement> Keyed and sorted by code. */
    private array $codes;

    /**
     * @param  array<int, CodeSettlement>  $codes  Re-keyed by code and sorted.
     * @param  list<DerivedSettlement>  $derived
     * @param  list<DirectorSettlement>  $directors
     * @param  list<PersonSettlement>  $persons
     * @param  list<Alert>  $alerts
     *
     * @throws InvalidArgumentException when the parameters belong to another period.
     */
    public function __construct(
        private Period $period,
        private PeriodParameters $parameters,
        array $codes,
        private array $derived,
        private array $directors,
        private array $persons,
        private array $alerts,
        private ControlTotals $controls,
    ) {
        if (! $parameters->period()->equals($period)) {
            throw new InvalidArgumentException(sprintf(
                'A %s settlement cannot use the parameters of %s.',
                $period->key(),
                $parameters->period()->key(),
            ));
        }

        $byCode = [];

        foreach ($codes as $settlement) {
            $byCode[$settlement->code()] = $settlement;
        }

        ksort($byCode);

        $this->codes = $byCode;
    }

    public function period(): Period
    {
        return $this->period;
    }

    public function parameters(): PeriodParameters
    {
        return $this->parameters;
    }

    /**
     * @return array<int, CodeSettlement> Keyed and sorted by code.
     */
    public function codes(): array
    {
        return $this->codes;
    }

    public function code(int $code): ?CodeSettlement
    {
        return $this->codes[$code] ?? null;
    }

    /**
     * Section 1 of the consolidated report.
     *
     * @return array<int, CodeSettlement>
     */
    public function regular(): array
    {
        return $this->inScope(CodeScope::Regular);
    }

    /**
     * Section 2: codes settled apart (81, 84, 90).
     *
     * @return array<int, CodeSettlement>
     */
    public function separate(): array
    {
        return $this->inScope(CodeScope::Separate);
    }

    /**
     * Codes loaded only to reconcile sales (80, 100).
     *
     * @return array<int, CodeSettlement>
     */
    public function control(): array
    {
        return $this->inScope(CodeScope::Control);
    }

    /** Total of section 1: the regular code totals plus the derived commissions. */
    public function regularTotal(): Money
    {
        return Money::sum(array_map(fn (CodeSettlement $code) => $code->total, $this->regular()))
            ->plus(Money::sum(array_map(fn (DerivedSettlement $derived) => $derived->commission, $this->derived)));
    }

    /**
     * @return list<DerivedSettlement>
     */
    public function derivedCommissions(): array
    {
        return $this->derived;
    }

    public function derived(string $key): ?DerivedSettlement
    {
        foreach ($this->derived as $derived) {
            if ($derived->rule->key === $key) {
                return $derived;
            }
        }

        return null;
    }

    /**
     * @return list<DirectorSettlement>
     */
    public function directors(): array
    {
        return $this->directors;
    }

    public function director(string $teamKey): ?DirectorSettlement
    {
        foreach ($this->directors as $director) {
            if ($director->team->key === $teamKey) {
                return $director;
            }
        }

        return null;
    }

    /**
     * @return list<PersonSettlement>
     */
    public function persons(): array
    {
        return $this->persons;
    }

    public function person(string $personId): ?PersonSettlement
    {
        foreach ($this->persons as $person) {
            if ($person->person->id === $personId) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        return $this->alerts;
    }

    /**
     * @return list<Alert>
     */
    public function alertsOf(AlertKind $kind): array
    {
        return array_values(array_filter($this->alerts, fn (Alert $alert) => $alert->kind === $kind));
    }

    /**
     * The alerts that stop the approval until justified (§9.7).
     *
     * @return list<Alert>
     */
    public function blockingAlerts(): array
    {
        return array_values(array_filter($this->alerts, fn (Alert $alert) => $alert->isBlocking()));
    }

    public function hasBlockingAlerts(): bool
    {
        return $this->blockingAlerts() !== [];
    }

    public function controls(): ControlTotals
    {
        return $this->controls;
    }

    /**
     * @return array<int, CodeSettlement>
     */
    private function inScope(CodeScope $scope): array
    {
        return array_filter($this->codes, fn (CodeSettlement $code) => $code->rule->scope === $scope);
    }
}
