<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every version of every settlement (§4 "Liquidacion", §6): version 1 and
 * one more per complementary settlement. An open version (borrador or
 * aprobada) is replaced in place as its revision grows; a closed one
 * (cerrada) is never overwritten.
 *
 * "resultado" is the engine's SettlementResult, with the parameters it was
 * calculated with, serialized, compressed and base64-encoded; its sha256 is
 * checked before it is read back. Users are e-mails, as the engine compares
 * them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones_liquidaciones', function (Blueprint $table) {
            $table->id();
            // YYYY-MM.
            $table->char('periodo', 7);
            $table->unsignedInteger('version');
            $table->unsignedInteger('revision');
            // borrador | aprobada | cerrada.
            $table->string('estado', 20);
            $table->string('calculado_por');
            $table->dateTime('calculado_en');
            $table->string('aprobado_por')->nullable();
            $table->dateTime('aprobado_en')->nullable();
            $table->string('cerrado_por')->nullable();
            $table->dateTime('cerrado_en')->nullable();
            // Why a complementary version was registered.
            $table->text('motivo')->nullable();
            $table->unsignedInteger('version_anterior')->nullable();
            // Alert key => justification, given at the approval.
            $table->json('justificaciones');
            $table->longText('resultado');
            $table->char('resultado_sha256', 64);
            // Sum of the person totals, for lists.
            $table->decimal('total_nomina', 18, 2);
            $table->unsignedInteger('alertas_bloqueantes');
            $table->timestamps();

            $table->unique(['periodo', 'version']);
            $table->index(['estado', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_liquidaciones');
    }
};
