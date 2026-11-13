<?php

namespace App\Services;

use App\Models\User;

/**
 * Forgot Password by email code (approved 2026-11-08). The person types a registered email address
 * OR a registered mobile number; either way the code goes to the account's registered, verified email
 * address. No SMS and no link.
 *
 * Identifiers are normalised to "email:<address>" or "mobile:<10 digits>". Nothing here is shown to
 * the person: the page answers the same way whether or not an account matches.
 */
class PasswordResetCodes
{
    public const VIA_EMAIL = 'email';

    public const VIA_MOBILE = 'mobile';

    /** "email:<lower-case address>" or "mobile:<10 digits>", or null when it is neither. */
    public static function normalize(mixed $input): ?string
    {
        $value = trim(is_string($input) ? $input : '');

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return self::VIA_EMAIL.':'.strtolower($value);
        }

        $mobile = SmsService::normalizeMobile($value);

        return $mobile ? self::VIA_MOBILE.':'.$mobile : null;
    }

    /** "email" or "mobile". */
    public static function via(string $identifier): string
    {
        return str_starts_with($identifier, self::VIA_MOBILE.':') ? self::VIA_MOBILE : self::VIA_EMAIL;
    }

    /** The account the identifier names, eligible or not, or null. */
    public static function account(string $identifier): ?User
    {
        [$via, $value] = explode(':', $identifier, 2) + [1 => ''];

        return match ($via) {
            self::VIA_EMAIL => User::whereRaw('LOWER(email) = ?', [$value])->first(),
            self::VIA_MOBILE => User::where('mobile', $value)->first(),
            default => null,
        };
    }

    /** The account that may receive a reset code for this identifier, or null. */
    public static function eligibleAccount(string $identifier): ?User
    {
        $user = self::account($identifier);

        return $user && self::refusal($user, self::via($identifier)) === null ? $user : null;
    }

    /**
     * Why this account may not receive a reset code, or null. The code proves the mailbox, so the
     * address must be verified, and the account active. An administrator resets only by typing their
     * email address, never through a mobile number. A recovered account waiting for a new password
     * may use it: the code is the same proof of the mailbox as the emailed link.
     */
    public static function refusal(User $user, string $via): ?string
    {
        return match (true) {
            $user->isDeleted() => 'deleted',
            $user->isDeactivated() => 'deactivated',
            ! $user->hasVerifiedEmail() => 'email_not_verified',
            $via === self::VIA_MOBILE && $user->isAdmin() => 'admin_by_mobile',
            default => null,
        };
    }
}
