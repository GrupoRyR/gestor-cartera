<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notices (avisos): one row per recipient, written through
 * AvisoService. read_at and resolved_at are independent: a notice can be
 * resolved before its recipient reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            // Cascade: a user removed for good takes their inbox along; soft deletes keep it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // CompanyCode value, when the notice belongs to one company.
            $table->string('company', 3)->nullable();
            $table->string('kind', 40);
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('subject_type', 20)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Groups the notices AvisoService::resolve() closes together.
            $table->string('key', 80)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // The bell: a user's unread notices. resolve(): the open notices of a key.
            $table->index(['user_id', 'read_at']);
            $table->index(['key', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
