<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manual captures of a period (§4, §7.3, §7.4): the monthly budget, the
 * OBR/Escudos sales, the reimbursements and deductions, and the crossing
 * reassignments. Manual crossings live in comisiones_cruces (origen manual).
 *
 * Every capture but the budget needs an approval by a user other than its
 * creator: estado pendiente | aprobado | rechazado. Only approved captures
 * enter a calculation. Supports are stored on disk, path in "soporte".
 */
return new class extends Migration
{
    public function up(): void
    {
        // The analyst's monthly load: no approval.
        Schema::create('comisiones_presupuestos', function (Blueprint $table) {
            $table->id();
            // YYYY-MM.
            $table->char('periodo', 7);
            $table->integer('slp_code');
            $table->decimal('valor', 18, 2);
            $table->string('canal', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['periodo', 'slp_code']);
        });

        Schema::create('comisiones_obr', function (Blueprint $table) {
            $table->id();
            $table->char('periodo', 7);
            $table->integer('slp_code');
            $table->string('descripcion', 500);
            $table->decimal('valor_vendido', 18, 2);
            // Percent number, "2.5" is 2.5 %.
            $table->decimal('tasa', 9, 4);
            $this->review($table);

            $table->index(['periodo', 'estado']);
        });

        Schema::create('comisiones_ajustes', function (Blueprint $table) {
            $table->id();
            $table->char('periodo', 7);
            $table->integer('slp_code');
            // reintegro | descuento.
            $table->string('tipo', 20);
            // Always positive; the type gives the sign.
            $table->decimal('valor', 18, 2);
            $table->string('descripcion', 500);
            $this->review($table);

            $table->index(['periodo', 'estado']);
        });

        // A user's correction of the seller of a crossing (§5.4).
        Schema::create('comisiones_reasignaciones', function (Blueprint $table) {
            $table->id();
            // comisiones_cruces.clave.
            $table->string('clave_cruce', 80);
            $table->integer('slp_code');
            $table->string('motivo', 500);
            $this->review($table);

            $table->index(['clave_cruce', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_reasignaciones');
        Schema::dropIfExists('comisiones_ajustes');
        Schema::dropIfExists('comisiones_obr');
        Schema::dropIfExists('comisiones_presupuestos');
    }

    /**
     * The support and approval columns of a capture.
     */
    private function review(Blueprint $table): void
    {
        $table->string('soporte')->nullable();
        // pendiente | aprobado | rechazado.
        $table->string('estado', 20)->default('pendiente');
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('revisado_en')->nullable();
        $table->string('motivo_rechazo', 500)->nullable();
        $table->timestamps();
    }
};
