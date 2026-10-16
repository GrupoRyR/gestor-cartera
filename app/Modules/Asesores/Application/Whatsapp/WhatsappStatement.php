<?php

namespace App\Modules\Asesores\Application\Whatsapp;

use App\Modules\Asesores\Application\Client\ClientFile;
use App\Modules\Asesores\Domain\Whatsapp\AccountMessage;
use App\Modules\Asesores\Domain\Whatsapp\PhoneNumber;
use App\Modules\Asesores\Domain\Whatsapp\WhatsappLink;
use App\Modules\Core\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Builds the WhatsApp message of a client account and the link that opens
 * it. The number comes from the contacts SAP gave us; nothing is sent from
 * here.
 */
final readonly class WhatsappStatement
{
    public function __construct(private CurrentCompany $companies) {}

    public function share(ClientFile $file, CarbonImmutable $today): WhatsappShare
    {
        $summary = $file->detail->summary;
        $message = AccountMessage::build(
            client: $summary->name,
            company: $this->companies->of($summary->company)->name,
            date: $today,
            invoices: $file->invoices,
            maxLines: (int) config('asesores.whatsapp.max_invoices'),
        );
        $number = PhoneNumber::firstWhatsapp($file->detail->contacts);

        return new WhatsappShare(WhatsappLink::url($number, $message), $number !== null);
    }
}
