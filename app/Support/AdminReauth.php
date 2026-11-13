<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Services\AdminMfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Sudo mode" for Admins: a sensitive Admin action needs a fresh two-factor verification (a code
 * from the authenticator app, or a recovery code) within accounts.admin_reauth_minutes.
 *
 * Only a two-factor check opens that window (owner decisions 3 and 4, 2026-11-11): the sign-in
 * challenge, enrolment, or the step-up page. A password never opens it. Where a password is still
 * asked for as an extra check (manual verification, the enrolment bootstrap), checkPassword()
 * limits it to 5 wrong tries per 15 minutes per account and audits each one.
 */
final class AdminReauth
{
    /** Renamed from admin.reauth_at, so a window that a password opened before this change is void. */
    public const SESSION_KEY = 'admin.stepup_mfa_at';

    /** The old key: only ever removed, never read. */
    public const LEGACY_KEY = 'admin.reauth_at';

    public const MAX_ATTEMPTS = 5;

    public static function fresh(Request $request): bool
    {
        $at = (int) $request->session()->get(self::SESSION_KEY, 0);

        return $at > 0 && (time() - $at) <= self::windowSeconds();
    }

    public static function secondsLeft(Request $request): int
    {
        $at = (int) $request->session()->get(self::SESSION_KEY, 0);

        return max(0, $at + self::windowSeconds() - time());
    }

    public static function windowSeconds(): int
    {
        return (int) config('accounts.admin_reauth_minutes', 10) * 60;
    }

    /**
     * The Admin's password as an extra check. Returns 'ok', 'invalid' or 'locked'. It never opens
     * the step-up window. A locked account is refused even with the right password.
     */
    public static function checkPassword(Request $request, string $password): string
    {
        $user = $request->user();
        $key = 'admin-reauth:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return 'locked';
        }

        if ($password !== '' && Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::clear($key);

            return 'ok';
        }

        RateLimiter::hit($key, 900);
        AuditLog::record('admin.reauth_failed', $user, [], $user->getKey());

        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) ? 'locked' : 'invalid';
    }

    /**
     * A code typed for step-up: from the authenticator app, or a recovery code. Returns 'ok',
     * 'recovery', 'invalid', 'locked' or 'not_enrolled'. Wrong codes share the sign-in challenge's
     * limit (5 per 15 minutes) and are audited there; a used recovery code is gone for good.
     */
    public static function verifyCode(Request $request, string $code): string
    {
        $user = $request->user();

        if (! self::canStepUp($request)) {
            return 'not_enrolled';
        }

        $result = app(AdminMfa::class)->verify($user, $code);

        if (in_array($result, ['ok', 'recovery'], true)) {
            self::grantFromMfa($request);
            AuditLog::record('admin.stepup_granted', $user, ['method' => $result === 'recovery' ? 'recovery_code' : 'authenticator'], $user->getKey());
        }

        return $result;
    }

    /** True when this Admin has a confirmed two-factor factor, so a step-up code can be checked. */
    public static function canStepUp(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && AdminMfa::available() && app(AdminMfa::class)->enrolled($user);
    }

    /** Opens the window after a successful two-factor check, with a new session id. */
    public static function grantFromMfa(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->forget(self::LEGACY_KEY);
        $request->session()->put(self::SESSION_KEY, time());
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, self::LEGACY_KEY]);
    }
}
