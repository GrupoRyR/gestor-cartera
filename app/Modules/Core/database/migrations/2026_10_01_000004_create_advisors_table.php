<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SAP sales employees of each company (OSLP), and the user each one is
 * linked to. Sign-in links them by e-mail (OSLP.Email, Part A.5); the
 * Ingesta import fills the table and the Equipo screen links them by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisors', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            // OSLP.SlpCode: unique only within its company.
            $table->unsignedInteger('code');
            $table->string('name');
            // Trimmed and in lowercase, like users.email, so they match.
            $table->string('email')->nullable()->index();
            $table->boolean('active')->default(true);
            // Users are soft-deleted, so the link survives; a user removed
            // for good leaves the advisor unlinked.
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company', 'code']);
            $table->foreign('company')->references('code')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisors');
    }
};
