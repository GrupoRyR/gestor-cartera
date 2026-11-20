<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment receipts (soportes de pago), one row each. The client fields
 * are copies taken at upload, so the inbox never depends on the current
 * snapshot or on the company the user has open. client_id is Core's client
 * id, kept without a foreign key: modules never constrain each other's
 * tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soportes', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            $table->unsignedBigInteger('client_id');
            $table->string('client_code', 30);
            $table->string('client_name');
            $table->unsignedInteger('advisor_code')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->string('method', 20)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pendiente');
            $table->string('receipt_number', 60)->nullable();
            $table->text('rejection_reason')->nullable();
            // Restrict, not null on delete: who uploaded a receipt must never
            // be lost. Users are soft-deleted, so the row always stays.
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['company', 'status']);
            $table->index('uploaded_by');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soportes');
    }
};
