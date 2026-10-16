<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The receivables snapshots (CargaCartera): one row per accepted cartera
 * load of a company, with its cutoff date and totals. Snapshots are never
 * overwritten (Part A.4): each one keeps its own invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_loads', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            $table->date('cutoff_date');
            // The Ingesta load (LoadRecord::$id) it came from; unique, so a
            // load is imported once.
            $table->unsignedBigInteger('load_id')->nullable()->unique();
            // LoadSource value: robot or manual.
            $table->string('source', 10);
            $table->unsignedInteger('invoice_count');
            $table->decimal('total', 18, 2);
            $table->decimal('overdue', 18, 2);
            $table->timestamps();

            $table->index(['company', 'cutoff_date']);
            $table->foreign('company')->references('code')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_loads');
    }
};
