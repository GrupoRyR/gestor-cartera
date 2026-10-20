<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;

/**
 * The kinds of parameter of the «Parámetros» screen (§7.2). The value is
 * the stored "tipo" (ParameterMapper::TYPES) and the {tipo} of the history and
 * form routes; tab() is the {pestana} of the screen.
 */
enum ParameterKind: string
{
    case Code = 'codigo';
    case Tranches = 'tramos';
    case Team = 'equipo';
    case Derived = 'derivada';
    case Options = 'opciones';

    /** The tab of the screen: codigos, tramos, equipos, derivadas, opciones. */
    public function tab(): string
    {
        return match ($this) {
            self::Code => 'codigos',
            self::Tranches => 'tramos',
            self::Team => 'equipos',
            self::Derived => 'derivadas',
            self::Options => 'opciones',
        };
    }

    public static function fromTab(string $tab): self
    {
        foreach (self::cases() as $kind) {
            if ($kind->tab() === $tab) {
                return $kind;
            }
        }

        return self::Code;
    }

    /**
     * @return list<string>
     */
    public static function tabs(): array
    {
        return array_map(fn (self $kind): string => $kind->tab(), self::cases());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * Whether the user names the key (codes, teams, derived commissions).
     * The tranche table and the options have a single key, their type.
     */
    public function keyed(): bool
    {
        return ! in_array($this, [self::Tranches, self::Options], true);
    }

    /**
     * The key of a version of this kind: the given one when keyed, else the type.
     */
    public function key(?string $key): string
    {
        return $this->keyed() ? trim((string) $key) : $this->value;
    }

    /**
     * «del código 14», «de la tabla de tramos», «del equipo Distribución»…
     * $version names a team by its channel.
     */
    public function of(string $key, ?ParameterRecord $version = null): string
    {
        $name = $this === self::Team && is_string($version?->datos['canal'] ?? null) ? $version->datos['canal'] : $key;

        return __('comisiones::parametros.de.'.$this->value, ['clave' => $name]);
    }
}
