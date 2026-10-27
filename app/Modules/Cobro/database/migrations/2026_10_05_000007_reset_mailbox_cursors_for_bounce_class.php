<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restarts the delta round of every company inbox once (audit S-24). Graph
 * keeps the query of the first page inside its next and delta links, so
 * the saved links would never ask for the message class the bounce rule
 * now needs. The next cobro:sincronizar-buzones starts a new round with
 * MailboxSync::initialQuery(); the messages already stored are skipped by
 * their Graph id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cobro_mailbox_cursors')->update(['delta_link' => null, 'next_link' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: the old links only lack the message class,
        // and a new round rebuilds them.
    }
};
