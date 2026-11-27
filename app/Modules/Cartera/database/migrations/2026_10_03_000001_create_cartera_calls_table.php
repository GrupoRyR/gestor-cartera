<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calls the collection team logs, one row each: channel, phone,
 * contact, outcome and agreement. client_id is Core's client id and
 * commitment_id and activity_id point to Core's commitments and activity
 * log, all kept without a foreign key: modules never constrain each
 * other's tables. A follow-up stays open until a later call of the client
 * closes it (follow_up_closed_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartera_calls', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3);
            $table->unsignedBigInteger('client_id');
            // Restrict, not null on delete: who made a call must never be
            // lost. Users are soft-deleted, so the row always stays.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->string('channel');
            $table->string('phone', 40);
            $table->string('contact_name', 150)->nullable();
            $table->string('outcome');
            $table->text('notes')->nullable();
            $table->string('agreement');
            $table->unsignedBigInteger('commitment_id')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->timestamp('follow_up_notified_at')->nullable();
            $table->timestamp('follow_up_closed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['follow_up_on', 'follow_up_notified_at', 'follow_up_closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartera_calls');
    }
};
