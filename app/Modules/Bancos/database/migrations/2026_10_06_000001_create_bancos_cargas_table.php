<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per statement imported (from the folder, the app or Treasury's
 * API): what was read, what was kept and what was left out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bancos_cargas', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            $table->string('bank', 20);
            $table->string('source', 10);
            $table->boolean('failed')->default(false);
            $table->string('file_name')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->unsignedInteger('movements_read')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('debits_skipped')->default(0);
            $table->unsignedInteger('ignored_rows')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company', 'bank', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bancos_cargas');
    }
};
