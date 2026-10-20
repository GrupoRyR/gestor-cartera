<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Modules\Comisiones\Http\Support\ParameterKind;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterCatalog;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * «Parámetros» (§7.2, comisiones.parameters), read only:
 *
 * - GET /comisiones/parametros/{pestana?}?fecha=YYYY-MM-DD
 *   (comisiones.parametros.index): one tab per kind (codigos, tramos,
 *   equipos, derivadas, opciones) with the version of each key in force on
 *   the date, today by default, and the change to come;
 * - GET /comisiones/parametros/{tipo}/{clave} (comisiones.parametros.historial):
 *   every version of a key with its validity, values, motive, author and
 *   date, the bitácora of §7.2.
 */
final class ParametersController
{
    public function __construct(
        private readonly ParameterCatalog $catalog,
    ) {}

    public function index(Request $request, ?string $pestana = null): View
    {
        $kind = ParameterKind::fromTab($pestana ?? ParameterKind::Code->tab());
        $day = self::day($request->query('fecha'));

        return view('comisiones::parametros.index', [
            'kind' => $kind,
            'day' => $day,
            'dayLabel' => self::label($day),
            'entries' => $this->catalog->entries($kind->value, $day),
            'defaults' => $kind === ParameterKind::Options ? $this->catalog->defaultOptions() : [],
            'closed' => $this->catalog->lastClosedPeriod(),
        ]);
    }

    public function history(string $tipo, string $clave): View
    {
        $kind = ParameterKind::from($tipo);
        $versions = $this->catalog->history($kind->value, $clave);

        abort_if($versions === [] && $kind->keyed(), 404);
        abort_if(! $kind->keyed() && $clave !== $kind->value, 404);

        return view('comisiones::parametros.historial', [
            'kind' => $kind,
            'key' => $clave,
            'versions' => $versions,
            'today' => self::today(),
            'closed' => $this->catalog->lastClosedPeriod(),
        ]);
    }

    /** Today in the application timezone (Bogotá), "Y-m-d". */
    public static function today(): string
    {
        return CarbonImmutable::now((string) config('app.timezone'))->format('Y-m-d');
    }

    /** "Y-m-d" as "d/m/Y". */
    private static function label(string $day): string
    {
        return implode('/', array_reverse(explode('-', $day)));
    }

    /**
     * A ?fecha= value as "Y-m-d", or today when it is missing or is not a day.
     */
    private static function day(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($day !== false && $day->format('Y-m-d') === $value) {
                return $value;
            }
        }

        return self::today();
    }
}
