<?php

namespace App\Modules\Bancos\Domain\Movement;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Where a statement came from. All three go through the same importer.
 */
enum ImportSource: string
{
    use HasOptions;

    /** A file dropped in the import folder, picked up by the daily command. */
    case Folder = 'carpeta';

    /** A file uploaded by a person in the app. */
    case App = 'app';

    /** Sent by Treasury's app to the API. */
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Folder => 'Carpeta',
            self::App => 'Subido en la app',
            self::Api => 'API de Tesorería',
        };
    }
}
