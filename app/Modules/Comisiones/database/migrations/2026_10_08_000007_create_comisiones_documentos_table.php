<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reports generated from a closed settlement version (§8, §10): the
 * collection PDF of each code (and 85.1), the consolidated report and the
 * payroll file. Once stored a document is immutable: a new download checks
 * its sha256 against the file on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('comisiones_liquidaciones')->restrictOnDelete();
            // recaudo | consolidado | nomina.
            $table->string('tipo', 20);
            // The code ("14"), "85.1", or the report name.
            $table->string('referencia', 30);
            // The file name offered on download.
            $table->string('archivo');
            // Path on the documents disk.
            $table->string('ruta');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['liquidacion_id', 'tipo', 'referencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_documentos');
    }
};
