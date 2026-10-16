<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The load history (historial de cargas): one row per batch received from
 * the robot or a manual upload, accepted or rejected. An accepted load keeps
 * its rows in ingesta_load_chunks as a dated snapshot that is never
 * overwritten; a rejected one keeps only its errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingesta_loads', function (Blueprint $table) {
            $table->id();
            // The sender's idempotency key (IncomingBatch::$batchId), of every load.
            $table->string('batch_id', 120)->index();
            // The same key, set only on accepted loads: a rejected batch id can
            // be sent again, and two concurrent copies of a batch collide here.
            $table->string('accepted_batch_id', 120)->nullable()->unique();
            // Dataset value, the {lote} segment of the robot endpoint.
            $table->string('dataset', 30);
            // CompanyCode value; no foreign key to Core's companies table.
            $table->string('company', 3);
            $table->date('cutoff_date');
            // LoadSource value: robot or manual.
            $table->string('source', 10);
            // LoadStatus value: aceptada or rechazada.
            $table->string('status', 10);
            $table->unsignedInteger('row_count');
            // Cartera loads only: sum of "saldo", and of "saldo" where "dias" > 0.
            $table->decimal('total', 18, 2)->nullable();
            $table->decimal('overdue', 18, 2)->nullable();
            // Row keys in stored order, so old chunks decode after a schema grows.
            $table->json('columns')->nullable();
            // Rejected loads: the summary and the messages per field ("rows.3.saldo").
            $table->text('error')->nullable();
            $table->json('errors')->nullable();
            $table->dateTime('received_at');
            // The user of a manual upload; null for the robot.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['dataset', 'company', 'status', 'cutoff_date']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingesta_loads');
    }
};
