<?php

namespace App\Modules\Cobro\Domain\Mail;

/**
 * A message as its mailbox holds it right now: its current Graph id and
 * its Outlook on the web link.
 */
final readonly class LocatedMessage
{
    /**
     * Hosts of Outlook on the web that webLink may point to; any other
     * link is dropped, so the app never redirects elsewhere.
     *
     * @var list<string>
     */
    public const array OUTLOOK_HOSTS = ['outlook.office365.com', 'outlook.office.com'];

    /**
     * @param  string  $id  Graph message id.
     * @param  string|null  $webLink  Outlook on the web link; null when Graph gave none or it is not an Outlook link.
     */
    public function __construct(
        public string $id,
        public ?string $webLink,
    ) {}

    /**
     * From a Graph message ($select=id,webLink); null without an id.
     *
     * @param  array<array-key, mixed>  $message
     */
    public static function fromGraph(array $message): ?self
    {
        $id = $message['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $link = $message['webLink'] ?? null;

        return new self($id, is_string($link) && self::isOutlookLink($link) ? $link : null);
    }

    private static function isOutlookLink(string $link): bool
    {
        $parts = parse_url($link);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::OUTLOOK_HOSTS, true)
            && ! isset($parts['user'], $parts['pass']);
    }
}
