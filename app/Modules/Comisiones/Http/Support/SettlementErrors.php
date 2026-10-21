<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Application\SettlementNotFound;
use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Settlement\InvalidTransition;
use App\Modules\Comisiones\Domain\Settlement\SegregationOfDuties;
use App\Modules\Comisiones\Domain\Settlement\SettlementLocked;
use App\Modules\Comisiones\Domain\Settlement\StaleSettlement;
use App\Modules\Comisiones\Domain\Settlement\UnresolvedAlerts;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalRefused;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use InvalidArgumentException;
use Throwable;

/**
 * The Spanish message the user sees for each exception of the settlement
 * flow (Application/README.md, "Map the exceptions"). The exception
 * messages are English and stay in the logs.
 */
final class SettlementErrors
{
    /**
     * The exceptions a settlement step may throw on purpose; anything else
     * is a bug and is left to the exception handler.
     *
     * @var list<class-string<Throwable>>
     */
    public const array HANDLED = [
        SettlementNotFound::class,
        SettlementLocked::class,
        InvalidTransition::class,
        StaleSettlement::class,
        SegregationOfDuties::class,
        UnresolvedAlerts::class,
        ApprovalRefused::class,
        CorruptSettlement::class,
        MissingParameters::class,
        InvalidArgumentException::class,
    ];

    public static function handles(Throwable $exception): bool
    {
        foreach (self::HANDLED as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $action  The step that failed: calcular, aprobar, cerrar or complementaria.
     */
    public static function message(Throwable $exception, Period $period, string $action): string
    {
        $replace = ['periodo' => $period->key()];

        return match (true) {
            $exception instanceof SettlementNotFound => __('comisiones::liquidacion.errores.sin_liquidacion', $replace),
            $exception instanceof SettlementLocked => __('comisiones::liquidacion.errores.cerrada', $replace),
            $exception instanceof InvalidTransition => __('comisiones::liquidacion.errores.transicion.'.$action, $replace),
            $exception instanceof StaleSettlement => __('comisiones::liquidacion.errores.desactualizada', [
                'antes' => $exception->expectedRevision(),
                'ahora' => $exception->currentRevision(),
            ]),
            $exception instanceof SegregationOfDuties => __('comisiones::liquidacion.errores.mismo_analista'),
            $exception instanceof UnresolvedAlerts => trans_choice('comisiones::liquidacion.errores.sin_justificar', count($exception->keys()), ['cantidad' => count($exception->keys())]),
            $exception instanceof ApprovalRefused => $exception->getMessage(),
            $exception instanceof CorruptSettlement => __('comisiones::liquidacion.errores.corrupta'),
            $exception instanceof MissingParameters => __('comisiones::liquidacion.errores.sin_parametros', $replace),
            default => __('comisiones::liquidacion.errores.datos_invalidos'),
        };
    }
}
