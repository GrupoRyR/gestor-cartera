<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every version of the commission parameters (§4, §7.2): codes, the tranche
 * table, director teams, derived commissions and the settlement options,
 * each with its validity. Edits are new versions (ParameterVersions); the
 * values are in "datos" (ParameterMapper describes them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones_parametros', function (Blueprint $table) {
            $table->id();
            // codigo | tramos | equipo | derivada | opciones.
            $table->string('tipo', 20);
            // The code ("14"), "tramos", the team key, the derived key ("81.1") or "opciones".
            $table->string('clave', 60);
            $table->date('vigente_desde');
            // Null: open-ended.
            $table->date('vigente_hasta')->nullable();
            $table->json('datos');
            $table->text('motivo')->nullable();
            // Null for the seeded values.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tipo', 'clave', 'vigente_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_parametros');
    }
};
