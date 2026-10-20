<?php

use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the parameter book once with the initial values of the
 * specification (Annex 1, §5.3, §5.5, §5.6), valid from 2026-01-01 and
 * open-ended. From then on the stored book is the source and users change
 * it only through new versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('comisiones_parametros')->exists()) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $rows = array_map(fn (array $record): array => [
            'tipo' => $record['tipo'],
            'clave' => $record['clave'],
            'vigente_desde' => $record['vigente_desde'],
            'vigente_hasta' => $record['vigente_hasta'],
            'datos' => json_encode($record['datos'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'motivo' => 'Valores iniciales de la especificación (anexo 1).',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], (new ParameterMapper)->fromBook(DefaultParameters::book('2026-01-01')));

        DB::table('comisiones_parametros')->insert($rows);
    }

    public function down(): void
    {
        DB::table('comisiones_parametros')->whereNull('created_by')->delete();
    }
};
