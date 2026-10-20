<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module's audit trail (§10, §7.9): parameter versions, loads, budget
 * and captures, calculations, approvals, closings and downloads. Insert-only:
 * AuditRecord refuses updates and deletes. Each event is also sent to Core's
 * AuditLogger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones_bitacora', function (Blueprint $table) {
            $table->id();
            // "comisiones.<entity>.<verb>", e.g. comisiones.settlement.approved.
            $table->string('evento', 100);
            // YYYY-MM, when the event belongs to a period.
            $table->char('periodo', 7)->nullable();
            // Null for the system (Ingesta loads, scheduled tasks).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // The user's name when it happened.
            $table->string('usuario');
            $table->string('sujeto_tipo', 50)->nullable();
            $table->string('sujeto_id', 64)->nullable();
            // field => ['old' => …, 'new' => …], or field => value.
            $table->json('cambios');
            $table->json('contexto');
            $table->text('motivo')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['evento', 'created_at']);
            $table->index(['periodo', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_bitacora');
    }
};
