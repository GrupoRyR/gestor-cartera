<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The overdue invoices a receipt covers, copied at upload. Core's invoice id
 * changes with every snapshot, so the SAP document number identifies the
 * invoice and the balance and days overdue are those of the upload day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soporte_facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soporte_id')->constrained('soportes')->cascadeOnDelete();
            $table->string('document_number', 40);
            $table->string('document_type', 20);
            $table->date('due_at');
            $table->decimal('balance', 15, 2);
            $table->integer('days_overdue');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soporte_facturas');
    }
};
