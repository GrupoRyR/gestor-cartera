<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Load alerts sent: one row per company, dataset, slot and kind, so the
 * same alert never goes out twice. Times are in the app timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingesta_load_alerts', function (Blueprint $table) {
            $table->id();
            // CompanyCode value; no foreign key to Core's companies table.
            $table->string('company', 3);
            // Dataset value of the load the alert is about.
            $table->string('dataset', 30);
            // The scheduled time of that load.
            $table->dateTime('slot_at');
            // LoadAlertKind value: falla, campana or no_llego.
            $table->string('kind', 10);
            // The final failure report behind a "falla" alert.
            $table->foreignId('load_failure_id')->nullable()->constrained('ingesta_load_failures')->nullOnDelete();
            $table->dateTime('created_at');

            $table->unique(['company', 'dataset', 'slot_at', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingesta_load_alerts');
    }
};
