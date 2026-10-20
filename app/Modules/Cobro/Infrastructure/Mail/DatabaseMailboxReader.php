<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\MailboxReader;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * MailboxReader over what MailboxSync stored in cobro_inbound_messages; it
 * never calls Graph.
 *
 * forClient() answers only for a client the signed-in user can read
 * (CarteraRepository::client(), so the scope rule of §6.2 applies);
 * unreadCount() counts the companies of CurrentCompany::scope() (empty for
 * guests) or the one given.
 */
final class DatabaseMailboxReader implements MailboxReader
{
    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function forClient(int $clientId, int $limit = 20): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, {$limit} given.");
        }

        if ($this->cartera->client($clientId) === null) {
            return [];
        }

        return InboundMessage::query()
            ->where('client_id', $clientId)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (InboundMessage $message): InboundMail => self::toInboundMail($message))
            ->values()
            ->all();
    }

    public function unreadCount(?CompanyCode $company = null): int
    {
        $companies = $company === null
            ? array_map(fn (Company $scoped): CompanyCode => $scoped->code, $this->currentCompany->scope())
            : [$company];

        if ($companies === []) {
            return 0;
        }

        return InboundMessage::query()->ofCompanies($companies)->whereNull('read_at')->count();
    }

    public static function toInboundMail(InboundMessage $message): InboundMail
    {
        return new InboundMail(
            id: (string) $message->id,
            company: $message->company,
            clientId: $message->client_id,
            from: $message->from_address ?? '',
            subject: $message->subject,
            preview: $message->preview,
            receivedAt: $message->received_at,
            isBounce: $message->is_bounce,
            bounceReason: $message->bounce_reason,
            conversationId: $message->conversation_id,
            read: $message->read_at !== null,
            url: Route::has('cobro.respuestas.show') ? route('cobro.respuestas.show', $message->id) : null,
        );
    }
}
