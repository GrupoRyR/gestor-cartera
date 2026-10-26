<?php

namespace App\Modules\Cobro\Domain\Copies;

use Illuminate\Support\Str;

/**
 * The carbon copies (Cc) of one client's weekly collection mail: the
 * company's fixed addresses, then the client's advisor (when enabled),
 * then the managers of the client's SAP group.
 *
 * Addresses are trimmed, blanks dropped and duplicates removed without
 * regard to case (the first spelling wins); an address already in To is
 * never copied. Groups match without regard to case, accents or spacing,
 * so "Cons.  residencial" matches SAP's "CONS. RESIDENCIAL"; a client
 * without a group gets no manager.
 */
final class CopyRecipients
{
    /**
     * @param  list<string>  $to  The mail's To addresses.
     * @return list<string>
     */
    public static function resolve(CopySettings $settings, ?string $advisorEmail, ?string $group, array $to = []): array
    {
        $addresses = $settings->fixed;

        if ($settings->advisor && $advisorEmail !== null) {
            $addresses[] = $advisorEmail;
        }

        $key = $group === null ? '' : self::groupKey($group);

        if ($key !== '') {
            foreach ($settings->managers as $manager) {
                if (self::groupKey($manager['group']) === $key) {
                    array_push($addresses, ...$manager['emails']);
                }
            }
        }

        return self::excluding($addresses, $to);
    }

    /**
     * $addresses without blanks, duplicates or any address of $to.
     *
     * @param  list<string>  $addresses
     * @param  list<string>  $to
     * @return list<string>
     */
    public static function excluding(array $addresses, array $to): array
    {
        $seen = array_fill_keys(array_map(fn (string $address): string => mb_strtolower(trim($address)), $to), true);
        $result = [];

        foreach ($addresses as $address) {
            $address = trim($address);
            $key = mb_strtolower($address);

            if ($address === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $address;
        }

        return $result;
    }

    /**
     * $addresses whose domain (the part after the last @, trimmed) is
     * exactly one of $domains without regard to case, in order: a
     * subdomain (ana@mail.segurex.com) does not count.
     *
     * @param  list<string>  $addresses
     * @param  list<string>  $domains
     * @return list<string>
     */
    public static function allowed(array $addresses, array $domains): array
    {
        $allowed = array_fill_keys(array_map(fn (string $domain): string => mb_strtolower(trim($domain)), $domains), true);
        unset($allowed['']);

        return array_values(array_filter(
            $addresses,
            fn (string $address): bool => isset($allowed[self::domain($address)]),
        ));
    }

    /**
     * The domain of an address as compared: after the last @, trimmed and
     * lowercase; empty without an @.
     */
    public static function domain(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : mb_strtolower(trim(substr($address, $at + 1)));
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    public static function unique(array $addresses): array
    {
        return self::excluding($addresses, []);
    }

    /**
     * The addresses of a text with one per line or separated by commas or
     * semicolons, unique.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        return self::unique(preg_split('/[\s,;]+/u', $text) ?: []);
    }

    /**
     * A group as compared: ASCII, uppercase, single spaces.
     */
    public static function groupKey(string $group): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($group))));
    }
}
