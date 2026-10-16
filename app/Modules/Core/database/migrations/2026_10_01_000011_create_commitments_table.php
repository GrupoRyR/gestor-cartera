<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment commitments (compromisos de pago) of the clients, written through
 * CompromisoService. Pending ones become Broken in Core's daily task;
 * Fulfilled and Cancelled are final. Commitments are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commitments', function (Blueprint $table) {
            $table->id();
            // The client's company, copied so a company's commitments read one index.
            $table->string('company', 3);
            // Restrict: a client with commitments is soft-deleted, never removed.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            // The client's OSLP.SlpCode when the commitment was created.
            $table->unsignedInteger('advisor_code')->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('due_date');
            // CommitmentStatus value.
            $table->string('status', 12)->default('pendiente');
            $table->text('notes')->nullable();
            // Restrict: users are soft-deleted, and Commitment::$createdBy is never empty.
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('broken_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['company', 'status', 'due_date']);
            $table->index('client_id');
            $table->foreign('company')->references('code')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commitments');
    }
};
