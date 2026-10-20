<?php

namespace App\Modules\Comisiones\Infrastructure\Parameters;

use RuntimeException;
use Throwable;

/**
 * A new parameter version was refused. The message is the Spanish text the
 * parameter screen shows; detail() keeps the engine's reason for the log.
 */
final class ParameterChangeRefused extends RuntimeException
{
    public function __construct(string $message, private readonly ?string $detail = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function reasonRequired(): self
    {
        return new self(__('comisiones::parametros.motivo_requerido'));
    }

    public static function unknownType(string $type): self
    {
        return new self(__('comisiones::parametros.tipo_desconocido', ['tipo' => $type]));
    }

    public static function invalidDate(string $date): self
    {
        return new self(__('comisiones::parametros.fecha_invalida', ['fecha' => $date]));
    }

    /**
     * @param  string  $period  The last closed period, "YYYY-MM".
     * @param  string  $firstDay  The first day a version may start, "d/m/Y".
     */
    public static function closedPeriod(string $period, string $firstDay): self
    {
        return new self(__('comisiones::parametros.periodo_cerrado', ['periodo' => $period, 'fecha' => $firstDay]));
    }

    /**
     * @param  string  $from  The start of the later version, "d/m/Y".
     */
    public static function laterVersion(string $from): self
    {
        return new self(__('comisiones::parametros.version_posterior', ['fecha' => $from]));
    }

    public static function invalidValues(Throwable $reason): self
    {
        return new self(__('comisiones::parametros.valores_invalidos'), $reason->getMessage(), $reason);
    }

    /** The engine's reason, in English, for the log; null when the message says it all. */
    public function detail(): ?string
    {
        return $this->detail;
    }
}
