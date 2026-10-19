<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every mail GraphMailSender handed to Graph, with the ids that join later
 * replies and bounces back to the send and to the client: the RFC 5322
 * Message-ID (internetMessageId) and the Graph conversationId.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_sent_messages', function (Blueprint $table) {
            $table->id();
            // CompanyCode value of the sender mailbox.
            $table->string('company', 3);
            $table->string('mailbox');
            // Platform client id (Core's clients table); no foreign key across modules.
            $table->unsignedBigInteger('client_id')->nullable()->index();
            // OutgoingMail::$category, e.g. cobro_semanal, circularizacion, prueba or alerta.
            $table->string('category', 40);
            $table->string('subject', 500);
            // To and Cc addresses, lowercase.
            $table->json('recipients');
            $table->string('internet_message_id', 500)->index();
            $table->string('conversation_id', 500)->nullable()->index();
            $table->dateTime('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_sent_messages');
    }
};
