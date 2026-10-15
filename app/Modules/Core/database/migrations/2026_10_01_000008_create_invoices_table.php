<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The open items of each snapshot: one row per invoice installment (or
 * credit note, payment or journal entry) of a client, as SAP reported it on
 * the cutoff date. Rows belong to their snapshot and are never updated; a
 * new snapshot brings its own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_load_id')->constrained()->cascadeOnDelete();
            $table->string('company', 3);
            // No cascade: a client with snapshot history is soft-deleted, never removed.
            $table->foreignId('client_id')->constrained();
            $table->string('document_number', 40);
            // SAP transaction type (JDT1.TransType), e.g. 13 for an invoice.
            $table->string('document_type', 20);
            $table->unsignedSmallInteger('installment')->nullable();
            $table->date('issued_at');
            $table->date('due_at');
            $table->decimal('amount', 18, 2);
            // Negative for credit notes and unapplied payments.
            $table->decimal('balance', 18, 2);
            // From the due date to the cutoff: positive = past due.
            $table->integer('days_overdue');
            $table->unsignedInteger('advisor_code')->nullable();

            $table->index(['portfolio_load_id', 'client_id']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
