<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies and bounces read from the company inboxes by
 * cobro:sincronizar-buzones, linked (when possible) to the mail they answer
 * and to its client; and the delta cursor of each inbox, so every run reads
 * only what arrived since the previous one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_inbound_messages', function (Blueprint $table) {
            $table->id();
            // CompanyCode value of the mailbox that received it.
            $table->string('company', 3);
            $table->string('mailbox');
            // Graph message id, unique within its mailbox.
            $table->string('graph_id');
            $table->string('internet_message_id', 500)->nullable();
            $table->string('conversation_id', 500)->nullable()->index();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject', 500);
            $table->text('preview');
            // Plain-text body, clipped; shown on the reply screen.
            $table->mediumText('body')->nullable();
            $table->dateTime('received_at');
            $table->boolean('is_bounce')->default(false);
            $table->string('bounce_reason', 500)->nullable();
            $table->string('bounced_recipient')->nullable();
            // The send it answers, and its client (Core's clients table, no foreign key).
            $table->foreignId('sent_message_id')->nullable()->constrained('cobro_sent_messages')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mailbox', 'graph_id']);
            $table->index(['client_id', 'received_at']);
            $table->index(['company', 'read_at']);
            $table->index('received_at');
        });

        Schema::create('cobro_mailbox_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox')->unique();
            // @odata.deltaLink of the last complete round, and the
            // @odata.nextLink of a round a run left unfinished.
            $table->text('delta_link')->nullable();
            $table->text('next_link')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_mailbox_cursors');
        Schema::dropIfExists('cobro_inbound_messages');
    }
};
