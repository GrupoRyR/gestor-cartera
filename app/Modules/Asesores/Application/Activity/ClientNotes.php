<?php

namespace App\Modules\Asesores\Application\Activity;

use App\Modules\Asesores\Domain\Activity\NoteText;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\GestionLogger;

/**
 * Adds a note to the log of a client through Core's GestionLogger, which
 * writes it as the signed-in user and only for clients inside their scope
 * (RecordNotFound otherwise).
 */
final readonly class ClientNotes
{
    public function __construct(private GestionLogger $log) {}

    public function add(int $clientId, string $text): Activity
    {
        $note = NoteText::split($text);

        return $this->log->log(new NewActivity(
            clientId: $clientId,
            type: ActivityType::Note,
            summary: $note->summary,
            details: $note->details,
        ));
    }
}
