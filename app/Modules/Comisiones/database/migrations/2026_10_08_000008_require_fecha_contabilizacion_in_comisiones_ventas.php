<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sale belongs to the period of its posting date, fecha_contabilizacion
 * (SAP DocDate), not of fecha_documento (SAP TaxDate). Ingesta already
 * requires it for the ventas dataset; rows staged before that take their
 * document date. The column is indexed for the period queries, like
 * fecha_documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('comisiones_ventas')
            ->whereNull('fecha_contabilizacion')
            ->update(['fecha_contabilizacion' => DB::raw('fecha_documento')]);

        Schema::table('comisiones_ventas', function (Blueprint $table) {
            $table->date('fecha_contabilizacion')->nullable(false)->change();

            $table->index('fecha_contabilizacion');
        });
    }

    public function down(): void
    {
        Schema::table('comisiones_ventas', function (Blueprint $table) {
            $table->dropIndex(['fecha_contabilizacion']);

            $table->date('fecha_contabilizacion')->nullable()->change();
        });
    }
};
