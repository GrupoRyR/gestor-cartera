<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank credits (money in) of every account, one row each. The fingerprint
 * is unique, so loading the same statement twice stores each movement once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bancos_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained('bancos_cargas');
            $table->string('company', 3);
            $table->string('bank', 20);
            $table->date('movement_date');
            $table->decimal('amount', 16, 2);
            $table->string('currency', 3)->default('COP');
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('account', 50)->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->string('status', 12)->default('pendiente');
            $table->string('receipt_number', 60)->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company', 'movement_date']);
            $table->index(['bank', 'movement_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bancos_movimientos');
    }
};
