<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Data\SentMail;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;

/**
 * In-memory MailSender: records every mail and never reaches Graph.
 *
 * The N-th sent mail gets the Message-ID "<fake-N@gestor-cartera.test>" and
 * the conversation id "fake-conversation-N".
 */
final class FakeMailSender implements MailSender
{
    /**
     * Sender mailbox by company code.
     */
    public const array MAILBOXES = [
        'SGX' => 'segurex.info@example.test',
        'SDC' => 'derivados.info@derivadoscol.com',
    ];

    /**
     * @var list<OutgoingMail>
     */
    private array $sent = [];

    /**
     * @var list<SentMail>
     */
    private array $receipts = [];

    private ?MailNotSent $failure = null;

    /**
     * The next send throws this exception and records nothing.
     */
    public function failNextWith(MailNotSent $exception): static
    {
        $this->failure = $exception;

        return $this;
    }

    public function send(OutgoingMail $mail): SentMail
    {
        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        $number = count($this->sent) + 1;
        $receipt = new SentMail(
            mailbox: self::MAILBOXES[$mail->company->value],
            internetMessageId: "<fake-{$number}@gestor-cartera.test>",
            conversationId: "fake-conversation-{$number}",
            sentAt: CarbonImmutable::now(),
        );

        $this->sent[] = $mail;
        $this->receipts[] = $receipt;

        return $receipt;
    }

    /**
     * @return list<OutgoingMail>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Receipts returned by send(), in the order of sent().
     *
     * @return list<SentMail>
     */
    public function receipts(): array
    {
        return $this->receipts;
    }

    /**
     * Asserts that mail passing the filter was sent: at least once, or
     * exactly $times times.
     *
     * @param  (callable(OutgoingMail): bool)|null  $filter
     */
    public function assertSent(?callable $filter = null, ?int $times = null): void
    {
        $count = count($filter === null ? $this->sent : array_filter($this->sent, $filter));

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, 'No matching mail was sent.');
        } else {
            Assert::assertSame($times, $count, "Expected {$times} matching mails, {$count} were sent.");
        }
    }

    /**
     * Asserts that a mail was sent to this address (To only, without regard to case).
     */
    public function assertSentTo(string $email): void
    {
        $email = mb_strtolower(trim($email));

        $this->assertSent(fn (OutgoingMail $mail): bool => in_array(
            $email,
            array_map(fn (string $to): string => mb_strtolower(trim($to)), $mail->to),
            true,
        ));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, count($this->sent).' mails were sent unexpectedly.');
    }
}
