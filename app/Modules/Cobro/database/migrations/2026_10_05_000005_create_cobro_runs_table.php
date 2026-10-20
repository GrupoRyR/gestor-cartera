<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs (lotes) of collection mail and their clients: the delivery report.
 * A run is one scheduled send of one kind (weekly notice or
 * circularization) for one company; each client gets one item with its
 * outcome: sent, bounced, without e-mail, excluded or failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_runs', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            // RunKind value: cobro_semanal or circularizacion.
            $table->string('kind', 30);
            // RunStatus value: enviando or terminado.
            $table->string('status', 20);
            // The scheduled moment (Bogotá); one run per company, kind and moment.
            $table->dateTime('scheduled_for');
            // Cutoff of the cartera snapshot the run used.
            $table->date('cutoff_date')->nullable();
            // Who started it by hand; null for the scheduler.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['company', 'kind', 'scheduled_for']);
            $table->index(['kind', 'started_at']);
        });

        Schema::create('cobro_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('cobro_runs')->cascadeOnDelete();
            // Platform client id (Core's clients table); no foreign key across modules.
            $table->unsignedBigInteger('client_id');
            $table->string('client_code', 50);
            $table->string('client_name');
            $table->string('tax_id', 50)->nullable();
            $table->json('recipients');
            $table->decimal('amount', 18, 2);
            $table->unsignedInteger('invoice_count');
            // ItemStatus value.
            $table->string('status', 20);
            $table->string('exclusion_reason', 500)->nullable();
            $table->foreignId('sent_message_id')->nullable()->constrained('cobro_sent_messages')->nullOnDelete();
            // Message-ID of the send, so a bounce finds its item.
            $table->string('internet_message_id', 500)->nullable()->index();
            $table->dateTime('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('bounce_reason', 500)->nullable();
            $table->string('bounced_recipient')->nullable();
            $table->dateTime('bounced_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'client_id']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_run_items');
        Schema::dropIfExists('cobro_runs');
    }
};
