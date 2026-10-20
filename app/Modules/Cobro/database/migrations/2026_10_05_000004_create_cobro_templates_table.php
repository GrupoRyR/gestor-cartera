<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The texts each company saved for a collection mail (RunKind). Without a
 * row the mail uses the default texts of cobro::plantillas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_templates', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            // RunKind value: cobro_semanal or circularizacion.
            $table->string('kind', 30);
            $table->string('subject', 200);
            $table->text('intro');
            $table->text('closing');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_templates');
    }
};
