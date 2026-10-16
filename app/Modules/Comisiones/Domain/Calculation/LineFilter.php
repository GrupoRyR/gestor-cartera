<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\CrossingLine;
use App\Modules\Comisiones\Domain\Input\PaymentLine;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The line filtering of §5.1, shared by sales, payments and crossings, for
 * one calculation: which lines count, in which amount and for which code.
 */
final class LineFilter
{
    public function __construct(
        private readonly PeriodParameters $parameters,
        private readonly AlertCollector $alerts,
    ) {}

    /**
     * The lines that count in the period, in their original order:
     * 1. lines dated outside the period are ignored silently (the robot sends
     *    days of the previous month);
     * 2. a repeated key keeps its first line and raises DuplicateLine (§9.5);
     * 3. annulled lines are left out.
     *
     * @template T of SaleLine|PaymentLine|CrossingLine
     *
     * @param  list<T>  $lines
     * @return list<T>
     */
    public function keep(array $lines): array
    {
        $period = $this->parameters->period();
        $seen = [];
        $kept = [];

        foreach ($lines as $line) {
            if (! $period->contains($line->periodDate())) {
                continue;
            }

            if (isset($seen[$line->key])) {
                $this->alerts->add(self::duplicate($line));

                continue;
            }

            $seen[$line->key] = true;

            if (! $line->annulled) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    /**
     * An amount of the line in pesos (§14.7). Lines in pesos at rate 1 keep
     * it. Otherwise AsReported keeps it and raises ForeignCurrency; Convert
     * multiplies it by the exchange rate, to cents.
     */
    public function pesos(Money $amount, SaleLine|PaymentLine|CrossingLine $line): Money
    {
        if (! $line->isForeignCurrency()) {
            return $amount;
        }

        if ($this->parameters->options()->currency === CurrencyTreatment::Convert) {
            return $amount->multipliedBy($line->exchangeRate)->toCents();
        }

        $this->alerts->add(new Alert(
            AlertKind::ForeignCurrency,
            $line->key,
            "La línea {$line->key} viene en {$line->currency} con TRM {$line->exchangeRate}; se tomó el valor reportado.",
            $line instanceof CrossingLine ? $line->sellerOverride : $line->sellerCode,
        ));

        return $amount;
    }

    /**
     * The rule of the line's seller code in the period. A line without code,
     * or with a code without rule, belongs to no code: it raises
     * UnknownSellerCode once per code (or per SAP text when there is no code)
     * and its amount stays unassigned (§4, §9.1).
     */
    public function rule(?int $code, string $sellerText): ?SellerCodeRule
    {
        $rule = $code === null ? null : $this->parameters->code($code);

        if ($rule !== null) {
            return $rule;
        }

        $this->alerts->add($code === null ? self::withoutCode($sellerText) : new Alert(
            AlertKind::UnknownSellerCode,
            (string) $code,
            "El código {$code} no existe en los parámetros del período; sus líneas quedan sin asignar.",
            $code,
        ));

        return null;
    }

    /**
     * The DuplicateLine alert of a repeated key. Its reference carries the
     * kind of line ("venta:F100-1", "pago:…", "cruce:…") because a sale, a
     * payment and a crossing may share a key without being the same line.
     */
    private static function duplicate(SaleLine|PaymentLine|CrossingLine $line): Alert
    {
        [$kind, $message] = match (true) {
            $line instanceof SaleLine => ['venta', "La venta {$line->key} viene repetida; se tomó solo la primera."],
            $line instanceof PaymentLine => ['pago', "El pago {$line->key} viene repetido; se tomó solo el primero."],
            $line instanceof CrossingLine => ['cruce', "El cruce {$line->key} viene repetido; se tomó solo el primero."],
        };

        return new Alert(AlertKind::DuplicateLine, "{$kind}:{$line->key}", $message);
    }

    private static function withoutCode(string $sellerText): Alert
    {
        $text = trim($sellerText);

        if ($text === '') {
            return new Alert(
                AlertKind::UnknownSellerCode,
                'sin vendedor',
                'Hay líneas sin vendedor en SAP; quedan sin asignar.',
            );
        }

        return new Alert(
            AlertKind::UnknownSellerCode,
            $text,
            "El vendedor «{$text}» no tiene código en SAP; sus líneas quedan sin asignar.",
        );
    }
}
