<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Domain\Mail\LocatedMessage;
use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;

/**
 * Finds a stored inbound message in its company mailbox, with the
 * credential of its company.
 *
 * The stored Graph id changes when someone moves the message to another
 * folder in Outlook (the sync does not ask for immutable ids). When Graph
 * answers 404 for it, the message is looked up again by its
 * internetMessageId, which never changes, and the new id is saved.
 */
final class MessageLocator
{
    private const string SELECT = 'id,webLink';

    public function __construct(
        private readonly GraphClient $graph,
    ) {}

    /**
     * The message as the mailbox holds it now; null when the mailbox no
     * longer has it.
     *
     * @throws GraphRequestFailed When Graph fails for another reason.
     */
    public function locate(InboundMessage $message): ?LocatedMessage
    {
        $graph = $this->graph->for($message->company);
        $messages = GraphClient::mailbox($message->mailbox).'/messages';

        try {
            return LocatedMessage::fromGraph($graph->get("{$messages}/".rawurlencode($message->graph_id), ['$select' => self::SELECT]));
        } catch (GraphRequestFailed $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }

        if (blank($message->internet_message_id)) {
            return null;
        }

        $found = $graph->get($messages, [
            '$filter' => "internetMessageId eq '".str_replace("'", "''", (string) $message->internet_message_id)."'",
            '$select' => self::SELECT,
            '$top' => 1,
        ]);
        $first = is_array($found['value'] ?? null) ? ($found['value'][0] ?? null) : null;
        $located = is_array($first) ? LocatedMessage::fromGraph($first) : null;

        if ($located !== null) {
            $this->remember($message, $located->id);
        }

        return $located;
    }

    /**
     * Saves the new id, unless another row of the mailbox already has it
     * (the sync stored the message again after it came back to the inbox).
     */
    private function remember(InboundMessage $message, string $graphId): void
    {
        $taken = InboundMessage::query()
            ->where('mailbox', $message->mailbox)
            ->where('graph_id', $graphId)
            ->exists();

        if (! $taken) {
            $message->update(['graph_id' => $graphId]);
        }
    }
}
