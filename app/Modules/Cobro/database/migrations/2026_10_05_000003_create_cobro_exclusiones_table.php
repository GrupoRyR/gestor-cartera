<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exclusion list (lista de exclusión): clients that receive no
 * collection mail (weekly notice and circularization), with the reason and
 * an optional date when the exclusion ends. One row per client; removing a
 * client from the list deletes its row (the audit log keeps the history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_exclusiones', function (Blueprint $table) {
            $table->id();
            // CompanyCode value of the client.
            $table->string('company', 3);
            // Platform client id (Core's clients table); no foreign key across modules.
            $table->unsignedBigInteger('client_id')->unique();
            // Shown in the list without asking Core for every row.
            $table->string('client_code', 50);
            $table->string('client_name');
            $table->string('tax_id', 50)->nullable();
            $table->string('reason', 500);
            // Last day the exclusion applies; null = until someone removes it.
            $table->date('expires_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_exclusiones');
    }
};
