<?php

namespace App\Support;

use Symfony\Component\Mime\Email;

/**
 * Removes secrets from an email before a log transport writes it to storage/logs.
 *
 * Two layers:
 * - exact values: a notification that carries a secret (an OTP, a recovery or bridge code, a
 *   reset token, a link signature) registers it with remember() while it builds the email, so
 *   it is removed wherever and however it appears;
 * - patterns, for anything not registered: reset-link tokens, signature= and token= query
 *   values, and "code is 123456" style codes.
 *
 * The registered values are kept as a short list in this process's memory, not tied to one
 * message object: the failover mailer hands each transport a clone, which a per-object record
 * would miss. Every logged email is cleaned of every value on the list. That can only ever mask
 * more text in a log copy, never reveal any. Entries expire after two hours and the list is
 * capped, and nothing on it is written anywhere.
 *
 * Only a copy written to a log is changed. The message delivered by SMTP keeps its working
 * links and codes.
 */
final class LogMailRedactor
{
    public const MARK = '[removed-in-log-mode]';

    private const KEEP_SECONDS = 7200;

    private const KEEP_MAX = 200;

    /** @var array<string, int> secret => expiry time */
    private static array $secrets = [];

    /** Registers values that must never reach a log. Values under 6 characters are ignored. */
    public static function remember(array $values): void
    {
        $now = time();
        self::$secrets = array_filter(self::$secrets, fn (int $until) => $until > $now);

        foreach ($values as $value) {
            $value = (string) $value;
            if (strlen($value) >= 6) {
                unset(self::$secrets[$value]);
                self::$secrets[$value] = $now + self::KEEP_SECONDS;
            }
        }

        self::$secrets = array_slice(self::$secrets, -self::KEEP_MAX, null, true);
    }

    /** @return list<string> the registered values still in force */
    public static function secrets(): array
    {
        $now = time();

        return array_map('strval', array_keys(array_filter(self::$secrets, fn (int $until) => $until > $now)));
    }

    /** A copy of the message with every secret removed from its text and HTML parts. */
    public static function redacted(Email $message): Email
    {
        $copy = clone $message;
        $secrets = self::secrets();

        if (is_string($copy->getHtmlBody())) {
            $copy->html(self::redactText($copy->getHtmlBody(), $secrets));
        }

        if (is_string($copy->getTextBody())) {
            $copy->text(self::redactText($copy->getTextBody(), $secrets));
        }

        $copy->subject(self::redactText((string) $copy->getSubject(), $secrets));

        return $copy;
    }

    public static function redactText(string $body, array $secrets = []): string
    {
        // Longest first, so a value containing a shorter one is removed whole.
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($secrets as $secret) {
            $body = str_replace([$secret, htmlspecialchars($secret, ENT_QUOTES), rawurlencode($secret)], self::MARK, $body);
        }

        return preg_replace([
            // /reset-password/{token}
            '#(/reset-password/)[^/?\s"\'<>&]+#',
            // /email-replacement/{id}/{token}
            '#(/email-replacement/\d+/)[A-Za-z0-9]{20,}#',
            // ?signature=... and &token=..., also written as &amp; in HTML
            '#((?:[?&]|&amp;)(?:signature|token)=)[^&\s"\'<>]+#i',
            // "Your code is 123456", "code: 123456", "OTP 123456"
            '#\b((?:code|otp)(?:\s+is)?\s*:?\s*)\d{4,10}\b#i',
        ], array_fill(0, 4, '$1'.self::MARK), $body);
    }
}
