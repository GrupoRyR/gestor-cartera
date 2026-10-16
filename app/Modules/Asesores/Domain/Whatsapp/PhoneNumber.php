<?php

namespace App\Modules\Asesores\Domain\Whatsapp;

use App\Modules\Core\Contracts\Data\Contact;

/**
 * Turns the phone numbers SAP has into the number a wa.me link needs: digits
 * only, with the country code. Only mobiles have WhatsApp, so a Colombian
 * landline gives nothing.
 */
final class PhoneNumber
{
    private const string COUNTRY = '57';

    /**
     * The WhatsApp number of $written, or null when it is not a mobile.
     * Colombian mobiles may come with or without the country code; a number
     * of another country must carry its code, behind "+" or "00".
     */
    public static function whatsapp(string $written): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $written);
        $international = str_starts_with(trim($written), '+');

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, self::COUNTRY) && ($international || strlen($digits) === strlen(self::COUNTRY) + 10)) {
            return self::isMobile(substr($digits, strlen(self::COUNTRY))) ? $digits : null;
        }

        if ($international) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
        }

        return self::isMobile($digits) ? self::COUNTRY.$digits : null;
    }

    /**
     * The number of the first contact that has a mobile; contacts come with
     * the primary one first.
     *
     * @param  list<Contact>  $contacts
     */
    public static function firstWhatsapp(array $contacts): ?string
    {
        foreach ($contacts as $contact) {
            $number = $contact->phone === null ? null : self::whatsapp($contact->phone);

            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Colombian mobiles have ten digits and start with 3.
     */
    private static function isMobile(string $national): bool
    {
        return preg_match('/^3\d{9}$/D', $national) === 1;
    }
}
