<?php

namespace App\Modules\Core\Console\Migracion;

use LogicException;

/**
 * The legacy systems the migration reads from. Every export file name
 * starts with the value of its source and an underscore
 * (fase1_users.csv, supabase_usuarios.csv).
 */
enum LegacySource: string
{
    /** Cartera Fase 1, the MySQL database exported with phpMyAdmin. */
    case Fase1 = 'fase1';

    /** The Supabase project of the sales advisors. */
    case Supabase = 'supabase';

    public static function ofFile(string $fileName): self
    {
        return self::tryFrom(strstr($fileName, '_', true) ?: '')
            ?? throw new LogicException("[{$fileName}] does not start with a legacy source prefix.");
    }

    public function label(): string
    {
        return match ($this) {
            self::Fase1 => 'Fase 1',
            self::Supabase => 'Supabase',
        };
    }
}
