<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only audit trail written by the AuditLogger contract
 * (Infrastructure\Audit\DatabaseAuditLogger). Rows are never updated or
 * deleted, so there is no updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 150)->index();
            $table->string('subject_type', 60)->nullable();
            // Ids may be integers or strings (SAP codes), so they are kept as text.
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            // Null for the system (jobs, console). Users are soft-deleted, so
            // the link survives; "actor" keeps the e-mail even if it does not.
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('actor')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
