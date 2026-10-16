<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clients of each company (OCRD): their SAP data, the person in charge
 * of the collection and the balance and status of the latest snapshot,
 * cached so the portfolio screens sort and filter without reading invoices.
 * Clients are soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            // OCRD.CardCode: unique only within its company.
            $table->string('code', 30);
            $table->string('name');
            // NIT (OCRD.LicTradNum).
            $table->string('tax_id', 30);
            $table->string('group')->nullable();
            $table->string('city')->nullable();
            $table->string('payment_terms')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();
            // The current OSLP.SlpCode of the client.
            $table->unsignedInteger('advisor_code')->nullable();
            // The person in charge of the collection (gestor responsable).
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            // ClientStatus value.
            $table->string('status', 20)->default('al_dia');
            $table->timestamp('status_changed_at')->nullable();
            // Cached from the latest snapshot of the company.
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('overdue', 18, 2)->default(0);
            $table->integer('max_days_overdue')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company', 'code']);
            $table->index(['company', 'status']);
            $table->index(['company', 'advisor_code']);
            $table->index('tax_id');
            $table->index('name');
            $table->foreign('company')->references('code')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
