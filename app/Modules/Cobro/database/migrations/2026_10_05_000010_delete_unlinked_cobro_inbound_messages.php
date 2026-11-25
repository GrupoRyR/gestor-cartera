<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The company mailbox is also the department's work mailbox, so
 * MailboxSync now stores only the messages InboundLinker links to a send.
 * This deletes, once, the rows stored before that rule that are linked to
 * no send (sent_message_id is null), in every mailbox, and says how many
 * per mailbox in the log and the console. The messages stay in Outlook.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mailboxes = DB::table('cobro_inbound_messages')
            ->whereNull('sent_message_id')
            ->distinct()
            ->orderBy('mailbox')
            ->pluck('mailbox');

        foreach ($mailboxes as $mailbox) {
            $deleted = DB::table('cobro_inbound_messages')
                ->where('mailbox', $mailbox)
                ->whereNull('sent_message_id')
                ->delete();

            Log::info('Cobro: deleted the stored inbox messages linked to no send.', ['mailbox' => (string) $mailbox, 'deleted' => $deleted]);
            $this->say("Buzón {$mailbox}: se borraron {$deleted} mensajes que no estaban unidos a ningún envío.");
        }
    }

    public function down(): void
    {
        // The deleted rows cannot be restored: they are not kept anywhere
        // else in the database. The messages are still in Outlook, and a
        // new sync round would skip them again, as they answer no send.
    }

    private function say(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("<info>{$message}</info>");
        }
    }
};
