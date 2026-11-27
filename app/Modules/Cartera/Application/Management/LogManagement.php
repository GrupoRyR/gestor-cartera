<?php

namespace App\Modules\Cartera\Application\Management;

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Support\Formatting\Money;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;

/**
 * Writes the collector's own entries in a client's log (Part B, module 2,
 * item 6): free notes and discounts offered. The entry of a call is written
 * by LogCall, and that of a payment commitment by CompromisoService itself.
 *
 * The log is Core's; every entry is made as the authenticated user and
 * follows the scope rule, so a client out of reach is RecordNotFound.
 */
final class LogManagement
{
    /**
     * Longest summary: the first line of a note, shortened.
     */
    private const int SUMMARY_LENGTH = 100;

    public function __construct(
        private readonly GestionLogger $log,
        private readonly Translator $translator,
    ) {}

    /**
     * The summary is the first line of the text, shortened; the whole text is
     * kept as the details unless the summary already says it all.
     *
     * @throws RecordNotFound
     */
    public function note(int $clientId, string $text): Activity
    {
        $text = trim($text);
        $summary = Str::limit(trim(Str::before($text, "\n")), self::SUMMARY_LENGTH - 1, '…', preserveWords: true);

        return $this->log->log(new NewActivity(
            clientId: $clientId,
            type: ActivityType::Note,
            summary: $summary,
            details: $text === $summary ? null : $text,
        ));
    }

    /**
     * @param  float  $amount  Pesos offered off the debt; above zero.
     *
     * @throws RecordNotFound
     */
    public function discount(int $clientId, float $amount, ?string $details): Activity
    {
        return $this->log->log(new NewActivity(
            clientId: $clientId,
            type: ActivityType::DiscountOffered,
            summary: $this->translator->get('cartera::gestion.resumen.descuento', ['valor' => Money::format($amount)]),
            details: $this->clean($details),
            metadata: ['discount_amount' => $amount],
        ));
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
