<?php

namespace App\Support;

/**
 * PROTECTED_ACCOUNTS: comma-separated email addresses of permanent Admin accounts that no other
 * Admin can edit, deactivate or delete. Matching is by email, without case.
 *
 * Entries are trimmed, lower-cased and de-duplicated. An entry that is not a valid email address
 * could never match an account, so it is dropped from the list and reported by
 * enotary:deploy-check. Nothing is protected when the variable is empty or unset.
 */
final class ProtectedAccounts
{
    /** @return array{valid: list<string>, invalid: list<string>} */
    public static function parse(?string $value): array
    {
        $valid = [];
        $invalid = [];

        foreach (explode(',', (string) $value) as $entry) {
            $email = strtolower(trim($entry));

            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $email;
            } elseif (! in_array($email, $valid, true)) {
                $valid[] = $email;
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }
}
