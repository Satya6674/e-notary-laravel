<?php

namespace App\Http\Controllers\Auth;

use App\Support\PasswordPolicy;
use App\Support\SensitiveInput;
use App\Http\Controllers\Controller;
use App\Jobs\RecordPasswordResetFailure;
use App\Jobs\SendPasswordResetCode;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PasswordResetCodes;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Forgot Password by email code (approved 2026-11-08).
 *
 * Step 1: the person types a registered email address OR a registered mobile number. A job run after
 *         the response finds the account and emails a 6-digit code to its registered, verified
 *         address (PasswordResetCodes, OtpService: hashed, 10 minutes, one use, 5 attempts, 60-second
 *         cooldown). Never SMS, never a link.
 * Step 2: the code is checked. Step 3: a new password is set, within the freshness window.
 *
 * Account enumeration: every answer and every timing is the same whether or not an account matches.
 * The page cannot say whether an email was sent, because that would say whether the account exists,
 * so it says what will happen if one matches. Wrong, expired and too-many answers at step 2 are
 * decided by the same rules for a missing account as for a real one (the session's own clock and
 * failure count), so they reveal nothing either.
 *
 * The emailed-link reset (steps "token" below) stays for links an administrator sends when restoring
 * an account; the public form no longer sends links.
 */
class ForgotPasswordController extends Controller
{
    /** Step 1, for every well-formed identifier, registered or not. */
    public const GENERIC_STATUS = 'If these details match an account, a verification code will be sent to its registered email address.';

    public const RESEND_WAIT = 'Please wait 60 seconds before requesting another code.';

    public const CODE_INVALID = 'The verification code is invalid.';

    public const CODE_EXPIRED = 'The verification code has expired. Please request a new one.';

    public const CODE_BLOCKED = 'Too many incorrect attempts. Please wait 15 minutes, then request a new code.';

    public const SESSION_EXPIRED = 'Your password reset session has expired. Please start again.';

    public const RESET_DONE = 'Your password has been reset. Please log in with your new password.';

    private const RETIRED_PASSWORD = 'Choose a different password. Your previous password cannot be used again.';

    private const BAD_IDENTIFIER = 'Enter a valid email address or 10-digit mobile number.';

    // Session keys of one reset attempt. The identifier is the one typed, never a looked-up account.
    private const S_IDENTIFIER = 'reset.identifier';

    private const S_REQUESTED = 'reset.requested_at';

    private const S_FAILURES = 'reset.failures';

    private const S_BLOCKED = 'reset.blocked_until';

    private const S_USER = 'reset.user_id';

    private const S_VERIFIED = 'reset.verified_at';

    public function showForgotForm()
    {
        return view('auth.forgot-password', [
            'awaitingCode' => session()->has(self::S_IDENTIFIER) && ! session()->has(self::S_USER),
            'via' => session()->has(self::S_IDENTIFIER) ? PasswordResetCodes::via((string) session(self::S_IDENTIFIER)) : null,
        ]);
    }

    /** Step 1: email address or mobile number. Also the "Send a new code" button. */
    public function sendResetLink(Request $request)
    {
        $request->validate(['identifier' => 'required|string|max:255']);

        $identifier = PasswordResetCodes::normalize($request->input('identifier'));

        if (! $identifier) {
            return back()->withErrors(['identifier' => self::BAD_IDENTIFIER])->withInput($request->except(SensitiveInput::FIELDS));
        }

        $same = session(self::S_IDENTIFIER) === $identifier;

        // The same answer for everyone: no account lookup happens on this request.
        if ($same && now()->timestamp - (int) session(self::S_REQUESTED) < OtpService::RESEND_SECONDS) {
            return redirect()->route('password.request')->with('error', self::RESEND_WAIT);
        }

        session()->forget([self::S_USER, self::S_VERIFIED]);
        session([
            self::S_IDENTIFIER => $identifier,
            self::S_REQUESTED => now()->timestamp,
            // A new identifier starts clean; the same one keeps its failures, so a lock-out holds.
            self::S_FAILURES => $same ? (int) session(self::S_FAILURES) : 0,
        ]);

        if (! $same) {
            session()->forget(self::S_BLOCKED);
        }

        SendPasswordResetCode::afterResponseOrQueue($identifier, $request->ip());

        return redirect()->route('password.request')->with('status', self::GENERIC_STATUS);
    }

    /** "Use a different email or mobile number": drop the attempt and start over. */
    public function restart(Request $request)
    {
        session()->forget([self::S_IDENTIFIER, self::S_REQUESTED, self::S_FAILURES, self::S_BLOCKED, self::S_USER, self::S_VERIFIED]);

        return redirect()->route('password.request');
    }

    /** Step 2: the 6-digit code from the email. */
    public function verifyOtp(Request $request, OtpService $otp)
    {
        $request->validate(['otp' => 'required|digits:6']);

        $identifier = session(self::S_IDENTIFIER);

        if (! is_string($identifier) || session()->has(self::S_USER)) {
            return redirect()->route('password.request')->withErrors(['otp' => self::SESSION_EXPIRED]);
        }

        $code = (string) $request->input('otp');

        if (now()->timestamp < (int) session(self::S_BLOCKED)) {
            $otp->spendTimingCheck($code);

            return redirect()->route('password.request')->withErrors(['otp' => self::CODE_BLOCKED]);
        }

        $user = PasswordResetCodes::eligibleAccount($identifier);
        $result = $user ? $otp->verifyPasswordResetCode($user, $code) : null;

        if (! $user) {
            // Costs the same as checking a real code.
            $otp->spendTimingCheck($code);
        }

        if ($result === OtpService::OK) {
            // A new session id, and a time limit on this one's proof: see resetPassword().
            session()->regenerate();
            session()->forget([self::S_FAILURES, self::S_BLOCKED]);
            session([self::S_USER => $user->id, self::S_VERIFIED => now()->timestamp]);

            return redirect()->route('password.reset.form');
        }

        // Audited after the response, and only for a real account, never with the identifier or code.
        RecordPasswordResetFailure::afterResponseOrQueue($identifier, $result ?? 'no_code', $request->ip());

        $failures = (int) session(self::S_FAILURES) + 1;
        session([self::S_FAILURES => $failures]);

        // Only this session's own count decides the wording. A real account locked from another
        // session still refuses every code (OtpService), but says "invalid" like a missing account
        // would, so a lock-out set elsewhere does not reveal that the account exists.
        if ($failures >= OtpService::MAX_ATTEMPTS) {
            session([self::S_BLOCKED => now()->addMinutes(OtpService::BLOCK_MINUTES)->timestamp]);

            return redirect()->route('password.request')->withErrors(['otp' => self::CODE_BLOCKED]);
        }

        // Decided by the session's clock, the same way whether or not an account matches.
        $expired = now()->timestamp - (int) session(self::S_REQUESTED) > OtpService::ttl() * 60;

        return redirect()->route('password.request')->withErrors(['otp' => $expired ? self::CODE_EXPIRED : self::CODE_INVALID]);
    }

    /** Step 3: the new-password form, only after a verified code. */
    public function showResetForm()
    {
        if (! session()->has(self::S_USER)) {
            return redirect()->route('password.request');
        }

        return view('auth.reset-password-form');
    }

    /** Step 3: save the new password for the account whose code was verified. */
    public function resetPassword(Request $request, OtpService $otp)
    {
        if (! session()->has(self::S_USER)) {
            return redirect()->route('password.request');
        }

        $data = $request->validate([
            'password' => PasswordPolicy::rules(),
        ]);

        $user = User::find((int) session(self::S_USER));
        $proofAge = now()->timestamp - (int) session(self::S_VERIFIED);
        $identifier = (string) session(self::S_IDENTIFIER);

        // Checked again: the account may have changed since the code was verified.
        if (! $user || $proofAge > OtpService::VERIFIED_WINDOW_MINUTES * 60 || ! $otp->hasRecentPasswordReset($user)
            || PasswordResetCodes::refusal($user, PasswordResetCodes::via($identifier)) !== null) {
            $this->forgetAttempt();

            return redirect()->route('password.request')->withErrors(['identifier' => self::SESSION_EXPIRED]);
        }

        if ($user->isRetiredPassword($data['password'])) {
            return back()->withErrors(['password' => self::RETIRED_PASSWORD]);
        }

        // The code proved the mailbox: the same proof a recovered account needs for a new password.
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ] + ($user->mustResetPassword() ? ['password_reset_required_at' => null] : []))->save();

        event(new PasswordReset($user));

        $otp->clear(OtpService::emailKey($user), OtpService::FLOW_PASSWORD_RESET_EMAIL);
        $this->forgetAttempt();

        return redirect()->route('home')->with('status', self::RESET_DONE);
    }

    private function forgetAttempt(): void
    {
        session()->forget([self::S_IDENTIFIER, self::S_REQUESTED, self::S_FAILURES, self::S_BLOCKED, self::S_USER, self::S_VERIFIED]);
    }

    /**
     * Token step 2: the form opened from a link an administrator sent when restoring an account.
     */
    public function showTokenResetForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Token step 3: let the broker validate the token and set the password.
     */
    public function resetPasswordToken(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => PasswordPolicy::rules(),
        ]);

        // Only after the token: otherwise this answer would confirm an old password to anyone.
        $holder = Password::broker()->getUser($request->only('email'));

        if ($holder?->isRetiredPassword($request->input('password')) && Password::broker()->tokenExists($holder, $request->input('token'))) {
            return back()->withInput($request->only('email'))->withErrors(['password' => self::RETIRED_PASSWORD]);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // Proof of the mailbox, the one way a recovered account gets a password again.
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ] + ($user->mustResetPassword() ? ['password_reset_required_at' => null] : []))->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);
        }

        return redirect()->route('home')
            ->with('status', 'Password reset successfully. Please login.');
    }
}
