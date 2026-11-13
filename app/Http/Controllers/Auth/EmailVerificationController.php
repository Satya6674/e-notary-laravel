<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\DeliveryLog;
use App\Services\MobileOtpEmailBridge;
use App\Services\OtpService;
use App\Services\VerificationLedger;
use App\Support\Platform;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Email-address verification. The email carries a 6-digit code only, typed in on the notice page
 * (OtpService: hashed, 10 minutes, one use, 5 attempts, cooldown and hourly limit). Verifying it
 * completes a Citizen's registration.
 *
 * The signed-link route below stays for links sent before the code-only email: a signed, expiring
 * URL with the user id and a hash of the address, that never signs anyone in and refuses a
 * different signed-in account. Resend is throttled in routes/web.php.
 */
class EmailVerificationController extends Controller
{
    private const RESEND_SECONDS = 60;

    /** Page telling the user to check their inbox (with a resend button). */
    public function notice(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->to($request->user()->landingUrl());
        }

        if (MobileOtpEmailBridge::enabled()) {
            return redirect()->route('verification.mobile.notice');
        }

        // Citizens see only whether their last code reached the mail service. Why it did not (Safe
        // Preview, a block, a missing setting) is for the Admin diagnostics, not this page.
        $delivery = $this->lastMessage($request->user());

        // A failed or blocked message is always reported. A simulated one only while Safe Preview is
        // still the mode: one recorded under Safe Preview before Live Mail was switched on says nothing
        // about sending now, so it must not show a failure the person did not have.
        $notSent = match ($delivery?->status) {
            MessageDelivery::FAILED, MessageDelivery::BLOCKED => true,
            MessageDelivery::SIMULATED => Platform::mailMode() === 'safe_preview',
            default => false,
        };

        return view('auth.verify-email', [
            'sent' => $delivery?->status === MessageDelivery::ACCEPTED,
            'notSent' => $notSent,
            'code' => app(OtpService::class)->status(OtpService::emailKey($request->user()), OtpService::FLOW_EMAIL_VERIFICATION),
        ]);
    }

    /**
     * The 6-digit code from the email. Proves the mailbox of the address the code was sent to:
     * after an email change the old code no longer matches.
     */
    public function verifyCode(Request $request, OtpService $otp)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->to($user->landingUrl());
        }

        abort_if($user->isDeactivated(), 403);

        $request->validate(['code' => 'required|digits:6']);

        $result = $otp->verifyEmailCode($user, (string) $request->input('code'));

        if ($result !== OtpService::OK) {
            AuditLog::record('auth.otp_failed', $user, ['flow' => OtpService::FLOW_EMAIL_VERIFICATION, 'reason' => $result], $user->id);

            return back()->withErrors(['code' => match ($result) {
                OtpService::BLOCKED => self::CODE_BLOCKED,
                OtpService::EXPIRED => self::CODE_EXPIRED,
                default => self::CODE_INVALID,
            }]);
        }

        $otp->clear(OtpService::emailKey($user), OtpService::FLOW_EMAIL_VERIFICATION);

        $user->forceFill(['email_verified_at' => null] + (User::verificationStatusSupported() ? ['email_verification_method' => User::METHOD_EMAIL_CODE] : []))->save();

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
            app(VerificationLedger::class)->record($user, VerificationLedger::PURPOSE_EMAIL, 'email_otp', 'email');
        }

        return redirect()->to($user->landingUrl())->with('status', 'Your email address has been verified.');
    }

    /** Target of the emailed link. The route has already checked the signature and expiry. */
    public function verify(Request $request, string $id, string $hash)
    {
        $user = User::find($id);

        abort_unless($user && hash_equals($hash, sha1($user->getEmailForVerification())), 403);
        abort_if($request->user() && $request->user()->id !== $user->id, 403);
        abort_if($user->isDeactivated(), 403);

        if (! $user->hasVerifiedEmail()) {
            // A signed link is real proof, and replaces any local development marker.
            $user->forceFill(['email_verified_at' => null] + (User::verificationStatusSupported() ? ['email_verification_method' => 'signed_link'] : []))->save();

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
                app(VerificationLedger::class)->record($user, VerificationLedger::PURPOSE_EMAIL, 'signed_link', 'email');
            }
        }

        // Citizens continue to the mobile (SMS OTP) step; others reach their dashboard.
        return redirect()->to($request->user() ? $user->landingUrl() : route('login'))
            ->with('status', 'Your email address has been verified.');
    }

    /**
     * Sends a new code at once and says only what really happened: sent when the mail service
     * accepted it, otherwise the plain failure message. When no email can leave at all (Safe
     * Preview, a missing Live Mail setting, an address outside the allowlist) no code is made.
     * Route throttling applies as well.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->to($user->landingUrl());
        }

        if (MobileOtpEmailBridge::enabled()) {
            return redirect()->route('verification.mobile.notice');
        }

        if (in_array(Platform::mailMode(), ['safe_preview', 'misconfigured'], true) || ! DeliveryLog::allowed('mail', $user->email)) {
            return back()->with('error', self::CODE_NOT_SENT);
        }

        // A message still on its way is not doubled. One waiting longer than this was not picked up
        // (no queue worker, or it stalled), so it must not block a new code for ever.
        $last = $this->lastMessage($user);

        if ($last && in_array($last->status, [MessageDelivery::QUEUED, MessageDelivery::SENDING], true)
            && $last->updated_at?->isAfter(now()->subMinutes(15))) {
            return back()->with('error', self::RESEND_WAIT);
        }

        $key = 'verification-resend:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('error', self::RESEND_WAIT);
        }

        RateLimiter::hit($key, self::RESEND_SECONDS);

        ['result' => $result, 'delivery' => $delivery] = $user->sendEmailVerification($request->ip());

        if ($result !== OtpService::ISSUED) {
            return back()->with('error', match ($result) {
                OtpService::COOLDOWN => self::RESEND_WAIT,
                OtpService::HOURLY_LIMIT => self::HOURLY_LIMIT,
                OtpService::DAILY_LIMIT => self::DAILY_LIMIT,
                OtpService::BLOCKED_NOW => self::CODE_BLOCKED,
                default => self::CODE_NOT_SENT,
            });
        }

        // The email was sent at once, so its record is final. "Sent" only once the mail service accepted it.
        return $delivery === MessageDelivery::ACCEPTED
            ? back()->with('status', self::CODE_SENT)
            : back()->with('error', self::CODE_NOT_SENT);
    }

    public const CODE_SENT = 'A verification code has been sent to your registered email address.';

    public const CODE_NOT_SENT = "We couldn't send your verification code. Please try again later.";

    public const CODE_INVALID = 'The verification code is invalid.';

    public const CODE_EXPIRED = 'The verification code has expired. Please request a new one.';

    public const CODE_BLOCKED = 'Too many incorrect attempts. Please wait 15 minutes, then request a new code.';

    public const RESEND_WAIT = 'Please wait 60 seconds before requesting another code.';

    public const HOURLY_LIMIT = 'You have requested the maximum of 5 codes this hour. Please try again later.';

    public const DAILY_LIMIT = 'You have reached the daily limit for verification codes. Please try again tomorrow.';

    private function lastMessage(User $user): ?MessageDelivery
    {
        if (! Platform::schemaReady()) {
            return null;
        }

        return MessageDelivery::where(['user_id' => $user->id, 'channel' => 'mail', 'purpose' => 'verify_email'])->latest('id')->first();
    }
}
