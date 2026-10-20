<?php

namespace App\Modules\Comisiones\Infrastructure\Parameters;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\CollectionTranche;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Parameters\DerivedCommissionRule;
use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\CrossingSellerDate;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\Options\SalesBasis;
use App\Modules\Comisiones\Domain\Parameters\OptionsVersion;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Parameters\TrancheTable;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;
use BackedEnum;
use InvalidArgumentException;

/**
 * Turns the parameter versions of the engine into the rows of
 * comisiones_parametros and back. A row is (tipo, clave, vigente_desde,
 * vigente_hasta, datos); "datos" holds the values of the version, with
 * percentages as percent strings ("0.75" is 0.75 %) and enums by value:
 *
 * - codigo (clave = the code): nombre, persona {id, nombre} or null, tasa_venta,
 *   tasa_recaudo, cumplimiento_minimo, alcance (regular | aparte | control), usa_tramos;
 * - tramos (clave "tramos"): tramos [{desde, hasta or null, participacion}];
 * - equipo (clave = team key): canal, director {id, nombre}, codigo_asociado or null,
 *   tasa, miembros [codes];
 * - derivada (clave = derived key, "81.1"): etiqueta, persona {id, nombre}, codigo_origen,
 *   base (venta | recaudo), tasa;
 * - opciones (clave "opciones"): base_venta, iva_venta, notas_credito, cruces_en_derivadas,
 *   cruces_en_directores, ubicacion_director, vendedor_cruce, alcance_cumplimiento, moneda.
 *
 * Reading checks the shape of "datos" and lets the domain check the values;
 * both throw InvalidArgumentException.
 */
final class ParameterMapper
{
    public const string CODE = 'codigo';

    public const string TRANCHES = 'tramos';

    public const string TEAM = 'equipo';

    public const string DERIVED = 'derivada';

    public const string OPTIONS = 'opciones';

    /** @var list<string> */
    public const array TYPES = [self::CODE, self::TRANCHES, self::TEAM, self::DERIVED, self::OPTIONS];

    /**
     * Every version of the book as rows, codes first, in book order.
     *
     * @return list<array{tipo: string, clave: string, vigente_desde: string, vigente_hasta: string|null, datos: array<string, mixed>}>
     */
    public function fromBook(ParameterBook $book): array
    {
        return array_map($this->toRecord(...), [
            ...$book->codes,
            ...$book->trancheTables,
            ...$book->teams,
            ...$book->derived,
            ...$book->options,
        ]);
    }

    /**
     * The book of these rows, in their order.
     *
     * @param  iterable<array{tipo: string, clave: string, vigente_desde: string, vigente_hasta: string|null, datos: array<string, mixed>}>  $records
     *
     * @throws InvalidArgumentException when a row is malformed or the versions overlap.
     */
    public function book(iterable $records): ParameterBook
    {
        $versions = [self::CODE => [], self::TRANCHES => [], self::TEAM => [], self::DERIVED => [], self::OPTIONS => []];

        foreach ($records as $record) {
            $versions[$record['tipo']][] = $this->fromRecord(
                $record['tipo'],
                $record['clave'],
                $record['vigente_desde'],
                $record['vigente_hasta'],
                $record['datos'],
            );
        }

        /** @var list<SellerCodeRule> $codes */
        $codes = $versions[self::CODE];
        /** @var list<TrancheTable> $tranches */
        $tranches = $versions[self::TRANCHES];
        /** @var list<DirectorTeam> $teams */
        $teams = $versions[self::TEAM];
        /** @var list<DerivedCommissionRule> $derived */
        $derived = $versions[self::DERIVED];
        /** @var list<OptionsVersion> $options */
        $options = $versions[self::OPTIONS];

        return new ParameterBook($codes, $tranches, $teams, $derived, $options);
    }

    /**
     * @return array{tipo: string, clave: string, vigente_desde: string, vigente_hasta: string|null, datos: array<string, mixed>}
     */
    public function toRecord(SellerCodeRule|TrancheTable|DirectorTeam|DerivedCommissionRule|OptionsVersion $version): array
    {
        [$type, $key, $data] = match (true) {
            $version instanceof SellerCodeRule => [self::CODE, (string) $version->code, [
                'nombre' => $version->name,
                'persona' => $version->person === null ? null : self::personData($version->person),
                'tasa_venta' => $version->saleRate->percent(),
                'tasa_recaudo' => $version->collectionRate->percent(),
                'cumplimiento_minimo' => $version->minimumCompliance->percent(),
                'alcance' => $version->scope->value,
                'usa_tramos' => $version->usesTranches,
            ]],
            $version instanceof TrancheTable => [self::TRANCHES, self::TRANCHES, [
                'tramos' => array_map(fn (CollectionTranche $tranche): array => [
                    'desde' => $tranche->fromDays,
                    'hasta' => $tranche->toDays,
                    'participacion' => $tranche->share->percent(),
                ], $version->tranches),
            ]],
            $version instanceof DirectorTeam => [self::TEAM, $version->key, [
                'canal' => $version->channel,
                'director' => self::personData($version->director),
                'codigo_asociado' => $version->associatedCode,
                'tasa' => $version->rate->percent(),
                'miembros' => $version->memberCodes,
            ]],
            $version instanceof DerivedCommissionRule => [self::DERIVED, $version->key, [
                'etiqueta' => $version->label,
                'persona' => self::personData($version->person),
                'codigo_origen' => $version->sourceCode,
                'base' => $version->base->value,
                'tasa' => $version->rate->percent(),
            ]],
            default => [self::OPTIONS, self::OPTIONS, self::optionsData($version->options)],
        };

        return [
            'tipo' => $type,
            'clave' => $key,
            'vigente_desde' => $version->validity->from->format('Y-m-d'),
            'vigente_hasta' => $version->validity->until?->format('Y-m-d'),
            'datos' => $data,
        ];
    }

    /**
     * The version a row describes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when the type is unknown, the data is malformed or the domain rejects it.
     */
    public function fromRecord(string $type, string $key, string $from, ?string $until, array $data): SellerCodeRule|TrancheTable|DirectorTeam|DerivedCommissionRule|OptionsVersion
    {
        $validity = Validity::from($from, $until);

        return match ($type) {
            self::CODE => new SellerCodeRule(
                $validity,
                self::code($key),
                self::text($data, 'nombre'),
                self::optionalPerson($data, 'persona'),
                self::percentage($data, 'tasa_venta'),
                self::percentage($data, 'tasa_recaudo'),
                self::percentage($data, 'cumplimiento_minimo'),
                self::enum($data, 'alcance', CodeScope::class),
                self::boolean($data, 'usa_tramos'),
            ),
            self::TRANCHES => new TrancheTable($validity, array_map(
                fn (mixed $tranche): CollectionTranche => is_array($tranche)
                    ? new CollectionTranche(
                        self::integer($tranche, 'desde'),
                        ($tranche['hasta'] ?? null) === null ? null : self::integer($tranche, 'hasta'),
                        self::percentage($tranche, 'participacion'),
                    )
                    : throw new InvalidArgumentException('Each tranche must be an object with desde, hasta and participacion.'),
                self::list($data, 'tramos'),
            )),
            self::TEAM => new DirectorTeam(
                $validity,
                $key,
                self::text($data, 'canal'),
                self::person($data, 'director'),
                ($data['codigo_asociado'] ?? null) === null ? null : self::integer($data, 'codigo_asociado'),
                self::percentage($data, 'tasa'),
                array_map(fn (mixed $code): int => self::integer(['miembro' => $code], 'miembro'), self::list($data, 'miembros')),
            ),
            self::DERIVED => new DerivedCommissionRule(
                $validity,
                $key,
                self::text($data, 'etiqueta'),
                self::person($data, 'persona'),
                self::integer($data, 'codigo_origen'),
                self::enum($data, 'base', DerivedBase::class),
                self::percentage($data, 'tasa'),
            ),
            self::OPTIONS => new OptionsVersion($validity, new SettlementOptions(
                salesBasis: self::enum($data, 'base_venta', SalesBasis::class),
                salesVatRate: self::percentage($data, 'iva_venta'),
                creditNotes: self::enum($data, 'notas_credito', CreditNoteTreatment::class),
                crossingsInDerivedCollections: self::boolean($data, 'cruces_en_derivadas'),
                crossingsInDirectorCollections: self::boolean($data, 'cruces_en_directores'),
                directorPlacement: self::enum($data, 'ubicacion_director', DirectorPlacement::class),
                crossingSeller: self::enum($data, 'vendedor_cruce', CrossingSellerDate::class),
                complianceScope: self::enum($data, 'alcance_cumplimiento', ComplianceScope::class),
                currency: self::enum($data, 'moneda', CurrencyTreatment::class),
            )),
            default => throw new InvalidArgumentException("Unknown parameter type {$type}."),
        };
    }

    /**
     * @return array{id: string, nombre: string}
     */
    private static function personData(PersonRef $person): array
    {
        return ['id' => $person->id, 'nombre' => $person->name];
    }

    /**
     * @return array<string, mixed>
     */
    private static function optionsData(SettlementOptions $options): array
    {
        return [
            'base_venta' => $options->salesBasis->value,
            'iva_venta' => $options->salesVatRate->percent(),
            'notas_credito' => $options->creditNotes->value,
            'cruces_en_derivadas' => $options->crossingsInDerivedCollections,
            'cruces_en_directores' => $options->crossingsInDirectorCollections,
            'ubicacion_director' => $options->directorPlacement->value,
            'vendedor_cruce' => $options->crossingSeller->value,
            'alcance_cumplimiento' => $options->complianceScope->value,
            'moneda' => $options->currency->value,
        ];
    }

    private static function code(string $key): int
    {
        if (preg_match('/^\d{1,9}$/', trim($key)) !== 1) {
            throw new InvalidArgumentException("Seller code {$key} is not a whole number.");
        }

        return (int) trim($key);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function text(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("The field {$field} must be text.");
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function integer(array $data, string $field): int
    {
        $value = $data[$field] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException("The field {$field} must be a whole number.");
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function percentage(array $data, string $field): Percentage
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("The field {$field} must be a percent number such as \"0.75\".");
        }

        return Percentage::of(is_string($value) ? trim($value) : $value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function boolean(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("The field {$field} must be true or false.");
        }

        return $value;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  array<array-key, mixed>  $data
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(array $data, string $field, string $enum): BackedEnum
    {
        $value = $data[$field] ?? null;
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw new InvalidArgumentException(sprintf('The field %s must be one of %s.', $field, implode(', ', array_map(
                fn (BackedEnum $option): string => (string) $option->value,
                $enum::cases(),
            ))));
        }

        return $case;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<mixed>
     */
    private static function list(array $data, string $field): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("The field {$field} must be a list.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function person(array $data, string $field): PersonRef
    {
        $value = $data[$field] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException("The field {$field} must be a person with id and nombre.");
        }

        return new PersonRef(self::text($value, 'id'), self::text($value, 'nombre'));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function optionalPerson(array $data, string $field): ?PersonRef
    {
        return ($data[$field] ?? null) === null ? null : self::person($data, $field);
    }
}
