<?php

namespace App\Modules\Comisiones\Domain\Calculation;

/**
 * Gathers the alerts of one calculation. The first alert of each key wins,
 * so a code or a line repeated many times raises a single alert.
 */
final class AlertCollector
{
    /** @var array<string, Alert> */
    private array $alerts = [];

    public function add(Alert $alert): void
    {
        $this->alerts[$alert->key()] ??= $alert;
    }

    /**
     * @return list<Alert> In the order they were first raised.
     */
    public function all(): array
    {
        return array_values($this->alerts);
    }
}
