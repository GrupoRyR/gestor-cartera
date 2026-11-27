<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The open invoices a call was about, copied when the call is logged.
 * invoice_id is Core's invoice id, kept without a foreign key; it changes
 * with every snapshot, so the SAP document number, due date and balance
 * are those of the call day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartera_call_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cartera_call_id')->constrained('cartera_calls')->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('document_number', 40);
            $table->string('document_type', 20);
            $table->date('due_at');
            $table->decimal('balance', 18, 2);

            $table->unique(['cartera_call_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartera_call_invoices');
    }
};
