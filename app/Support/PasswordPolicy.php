<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * The one password policy for every password a person chooses (registration, both reset flows,
 * Citizen and Notary password changes, an Admin setting a password). It applies only when a password
 * is created or changed: existing passwords keep working until their owner changes them.
 *
 * Every new password needs at least 8 characters with an uppercase letter, a lowercase letter, a
 * number and a special character (Laravel's Password rule: min, mixedCase, numbers, symbols), and
 * at most 72 bytes (bcrypt's limit, counted in bytes). A numbers-only password gets its own plain
 * explanation first. The same rule is registered as
 * Password::defaults() (AppServiceProvider), so any later use of Password::defaults() matches.
 *
 * The console command enotary:admin-reset keeps its stricter rule (12 characters, same character
 * classes) and uses the same 72-byte limit (maxBytes()).
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** bcrypt uses only the first 72 bytes of a password (see maxBytes()). */
    public const MAX_BYTES = 72;

    /** Laravel's Password rule with all four requirements. */
    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers()->symbols();
    }

    /**
     * Validation rules for a new password field.
     *
     * @param  bool  $required  false for "leave blank to keep the current password" forms
     * @param  bool  $confirmed  require a matching {field}_confirmation
     * @return list<mixed>
     */
    public static function rules(bool $required = true, bool $confirmed = true): array
    {
        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'string',
            self::maxBytes(),
            self::notNumbersOnly(),
            self::rule(),
            $confirmed ? 'confirmed' : null,
        ]));
    }

    /**
     * At most MAX_BYTES bytes of UTF-8. Passwords are hashed with bcrypt, which uses only the first
     * 72 bytes, so two longer passwords sharing those bytes would be the same password. Counted in
     * bytes (strlen), not characters: Laravel's max: rule counts characters, and a character outside
     * plain English letters takes 2-4 bytes. Applies to new or changed passwords only; signing in has
     * no length limit, so existing passwords (and hashes) keep working exactly as before.
     */
    public static function maxBytes(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::MAX_BYTES) {
                $fail(__('A password can be at most 72 bytes long (72 English letters, fewer if it uses other scripts).'));
            }
        };
    }

    /** A numbers-only password (such as 12345678) is refused with one clear explanation. */
    public static function notNumbersOnly(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ctype_digit($value)) {
                $fail(__('A password cannot be numbers only. Use uppercase and lowercase letters, a number and a special character.'));
            }
        };
    }
}
