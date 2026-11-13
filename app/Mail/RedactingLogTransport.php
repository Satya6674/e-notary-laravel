<?php

namespace App\Mail;

use App\Support\LogMailRedactor;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The "log" mail transport, wrapped: every email is redacted before the log transport writes it
 * to storage/logs. It replaces the framework's log transport wherever one is built, so the
 * protection also covers the failover mailer falling back to the log, not only MAIL_MAILER=log.
 */
final class RedactingLogTransport implements TransportInterface
{
    public function __construct(private TransportInterface $inner)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Email) {
            $message = LogMailRedactor::redacted($message);
        }

        return $this->inner->send($message, $envelope);
    }

    public function inner(): TransportInterface
    {
        return $this->inner;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
