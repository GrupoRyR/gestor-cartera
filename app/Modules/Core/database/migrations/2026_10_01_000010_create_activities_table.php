<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clients' activity log (gestiones / bitácora): calls, notes,
 * commitments, discounts, e-mails and status changes, written by a user or
 * by the platform. Entries are soft-deleted; the log reads them newest
 * first, per client or per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            // The client's company, copied so the recent entries of a company read one index.
            $table->string('company', 3);
            // No cascade: a client with a log is soft-deleted, never removed.
            $table->foreignId('client_id')->constrained();
            // ActivityType value.
            $table->string('type', 20);
            $table->string('summary');
            $table->text('details')->nullable();
            // Outcome of a call.
            $table->string('result', 150)->nullable();
            // The author; null when the platform wrote it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            // array<string, scalar|null> (NewActivity::$metadata).
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['company', 'occurred_at']);
            $table->foreign('company')->references('code')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
