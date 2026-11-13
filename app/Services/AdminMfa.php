<?php

namespace App\Services;

use App\Models\AdminMfaFactor;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin two-factor sign-in with an authenticator app (TOTP, RFC 6238: HMAC-SHA1, 30-second steps,
 * 6 digits) and one-time recovery codes. No third-party package: the algorithm is small and fixed.
 *
 * Rules:
 * - Enrollment needs a fresh password confirmation and a valid code from the app before it counts.
 * - A code is accepted for the current step +/- 1 (clock drift), and never for a step already used.
 * - 5 wrong codes lock the challenge for 15 minutes (per account, server side).
 * - A recovery code works once. Recovery codes are bcrypt-hashed, then encrypted with APP_KEY.
 * - Every enrollment, removal and recovery-code use is audited (enforced) and the Admin is alerted.
 * - Until the admin_mfa_factors table exists (migration 2026_11_10_000031) the feature is off while it
 *   is optional. When it is REQUIRED and the table is missing, or a lookup fails, EnsureAdminMfa
 *   refuses every Admin page (fail closed); it never lets an Admin through unchecked.
 */
class AdminMfa
{
    public const SESSION_KEY = 'admin_mfa.passed_user';

    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    public const RECOVERY_CODES = 8;

    private const STEP = 30;

    private const DIGITS = 6;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('admin_mfa_factors');
    }

    /** For tests that build the table after the first check. */
    public static function flushAvailability(): void
    {
        self::$available = null;
    }

    public static function required(): bool
    {
        return config('accounts.admin_mfa_required') === true;
    }

    public function factor(User $user): ?AdminMfaFactor
    {
        return self::available() ? AdminMfaFactor::where('user_id', $user->getKey())->first() : null;
    }

    public function enrolled(User $user): bool
    {
        return (bool) $this->factor($user)?->isConfirmed();
    }

    public static function passed(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::SESSION_KEY) === (int) $user->getKey();
    }

    /** A new secret for an Admin who is not enrolled yet. An enrolled factor is never replaced here. */
    public function start(User $user): string
    {
        $secret = self::randomSecret();

        DB::transaction(function () use ($user, $secret) {
            $factor = AdminMfaFactor::where('user_id', $user->getKey())->lockForUpdate()->first();

            if ($factor?->isConfirmed()) {
                throw new \RuntimeException('Two-factor sign-in is already on for this account.');
            }

            $factor ??= new AdminMfaFactor(['user_id' => $user->getKey()]);
            $factor->secret = $secret;
            $factor->recovery_codes = null;
            $factor->last_used_step = null;
            $factor->save();
        });

        return $secret;
    }

    /**
     * Finishes enrollment with a code from the app. Returns the plain recovery codes (shown once), or
     * null when the code is wrong. The audit entry is part of the same transaction.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        $codes = DB::transaction(function () use ($user, $code) {
            $factor = AdminMfaFactor::where('user_id', $user->getKey())->lockForUpdate()->first();

            if (! $factor || $factor->isConfirmed()) {
                return null;
            }

            $step = $this->matchingStep($factor->secret, $code, null);

            if ($step === null) {
                return null;
            }

            [$plain, $hashed] = self::recoveryCodes();
            $factor->forceFill(['confirmed_at' => now(), 'last_used_step' => $step, 'last_used_at' => now(), 'recovery_codes' => $hashed])->save();
            AuditLog::recordOrFail('admin.mfa_enabled', $user, [], $user->getKey());

            return $plain;
        });

        if ($codes !== null) {
            $user->notify(new SecurityAlertNotification('mfa_enabled'));
        }

        return $codes;
    }

    /**
     * Checks a sign-in code or recovery code. Returns 'ok', 'recovery' (a recovery code was used),
     * 'invalid' or 'locked'. Simultaneous requests are serialised on the factor row, so one code
     * cannot pass twice.
     */
    public function verify(User $user, string $input): string
    {
        $key = 'admin-mfa:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return 'locked';
        }

        $input = trim($input);

        $result = DB::transaction(function () use ($user, $input) {
            $factor = AdminMfaFactor::where('user_id', $user->getKey())->lockForUpdate()->first();

            if (! $factor || ! $factor->isConfirmed()) {
                return 'invalid';
            }

            if (preg_match('/^\d{6}$/', $input)) {
                $step = $this->matchingStep($factor->secret, $input, $factor->last_used_step);

                if ($step === null) {
                    return 'invalid';
                }

                $factor->forceFill(['last_used_step' => $step, 'last_used_at' => now()])->save();

                return 'ok';
            }

            $normalised = strtoupper(str_replace([' ', '-'], '', $input));

            foreach ((array) $factor->recovery_codes as $i => $hash) {
                if ($normalised !== '' && Hash::check($normalised, $hash)) {
                    $remaining = (array) $factor->recovery_codes;
                    unset($remaining[$i]);
                    $factor->forceFill(['recovery_codes' => array_values($remaining), 'last_used_at' => now()])->save();
                    AuditLog::recordOrFail('admin.mfa_recovery_code_used', $user, ['remaining' => count($remaining)], $user->getKey());

                    return 'recovery';
                }
            }

            return 'invalid';
        });

        if ($result === 'invalid') {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            AuditLog::record('admin.mfa_failed', $user, [], $user->getKey());

            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                return 'locked';
            }
        } else {
            RateLimiter::clear($key);
        }

        if ($result === 'recovery') {
            $user->notify(new SecurityAlertNotification('mfa_recovery_used'));
        }

        return $result;
    }

    /** @return list<string> plain new recovery codes */
    public function regenerateRecoveryCodes(User $user): array
    {
        [$plain, $hashed] = self::recoveryCodes();

        DB::transaction(function () use ($user, $hashed) {
            $factor = AdminMfaFactor::where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            $factor->forceFill(['recovery_codes' => $hashed])->save();
            AuditLog::recordOrFail('admin.mfa_recovery_codes_regenerated', $user, [], $user->getKey());
        });

        $user->notify(new SecurityAlertNotification('mfa_recovery_regenerated'));

        return $plain;
    }

    /** Removes the factor. $actor is null for the console recovery command. */
    public function disable(User $user, ?User $actor, string $action = 'admin.mfa_disabled'): bool
    {
        $removed = DB::transaction(function () use ($user, $actor, $action) {
            $deleted = AdminMfaFactor::where('user_id', $user->getKey())->delete();

            if ($deleted) {
                AuditLog::recordOrFail($action, $user, [], $actor?->getKey());
            }

            return $deleted > 0;
        });

        if ($removed) {
            $user->notify(new SecurityAlertNotification('mfa_disabled'));
        }

        return $removed;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) $this->factor($user)?->recovery_codes);
    }

    public static function otpauthUri(User $user, string $secret): string
    {
        $issuer = (string) config('app.name', 'E-Notary');
        $label = rawurlencode($issuer.':'.$user->email);

        return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * The setup address as a QR code, an SVG data URI for an <img>. Made on this server (no external
     * service sees the secret), shown only on the signed-in Admin's setup page, never stored or sent.
     */
    public static function qrDataUri(string $otpauthUri): string
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(240, 2),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );

        return 'data:image/svg+xml;base64,'.base64_encode((new \BaconQrCode\Writer($renderer))->writeString($otpauthUri));
    }

    /** The current code for a secret: used by tests and nothing else in the application. */
    public static function codeAt(string $secret, int $timestamp): string
    {
        return self::hotp(self::base32Decode($secret), intdiv($timestamp, self::STEP));
    }

    private function matchingStep(string $secret, string $code, ?int $lastUsed): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $key = self::base32Decode($secret);
        $now = intdiv(time(), self::STEP);

        foreach ([0, -1, 1] as $offset) {
            $step = $now + $offset;

            if ($lastUsed !== null && $step <= $lastUsed) {
                continue;
            }

            if (hash_equals(self::hotp($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function randomSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret))) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** @return array{0: list<string>, 1: list<string>} plain codes and their hashes */
    private static function recoveryCodes(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = strtoupper(Str::random(5).'-'.Str::random(5));
            $code = strtr($code, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'W', 'L' => 'V']);
            $plain[] = $code;
            $hashed[] = Hash::make(str_replace('-', '', $code));
        }

        return [$plain, $hashed];
    }
}
