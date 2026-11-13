<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\MobileEmailFallbackCodeNotification;
use App\Notifications\SecurityAlertNotification;
use App\Services\DeliveryLog;
use App\Services\MobileOtpEmailBridge;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Services\VerificationLedger;
use App\Support\Platform;
use App\Support\VerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Mobile number verification by SMS OTP: the second, compulsory sign-up step
 * for citizen accounts (after the email link). The routes sit behind auth +
 * verified (email) + role:public but NOT behind mobile.verified, and are rate
 * limited in routes/web.php. The code is issued and checked by OtpService;
 * users.mobile_verified_at is set only after a successful server-side check.
 *
 * Until migration 2026_10_29_000001 adds that column,
 * User::mobileVerificationSupported() is false: the step is skipped and
 * these actions just send the user to their dashboard.
 *
 * While real SMS is not ready (VerificationStatus::MODE_EMAIL_FALLBACK), the same button sends a
 * mobile-purpose code to the account's verified email instead, and no SMS provider is called. Passing
 * it records an email fallback (method email_fallback, SMS not_sent) and never sets
 * mobile_verified_at, which stays reserved for a code delivered by SMS.
 */
class MobileVerificationController extends Controller
{
    private const MAX_NUMBER_CHANGES_PER_DAY = 3;

    /** Step page: masked number, "send code", the code form, and a way to fix a wrong number. */
    public function notice(Request $request, OtpService $otp)
    {
        $user = $request->user();

        if (! User::mobileVerificationSupported()) {
            return redirect()->route($user->homeRoute())->with('error', 'Mobile verification is not enabled yet.');
        }

        if ($user->mobileStepDone() || $this->closedWhileAwaitingApproval($user)) {
            return redirect()->route($this->landingRoute($user));
        }

        $fallback = $this->fallback();
        $status = $otp->status((string) $user->mobile, $fallback ? OtpService::FLOW_MOBILE_EMAIL_FALLBACK : OtpService::FLOW_MOBILE_VERIFICATION);

        return view('auth.verify-mobile', [
            'fallback' => $fallback,
            'safePreview' => Platform::mailMode() === 'safe_preview',
            'user' => $user,
            'otp' => $status,
            'bridge' => MobileOtpEmailBridge::enabled(),
            'bridgeCode' => MobileOtpEmailBridge::prefill($user, $status['active']),
            'bridgeAutoSend' => $user->mobile && MobileOtpEmailBridge::shouldAutoSend($status),
        ]);
    }

    /** Send (or re-send, subject to the cooldown) a code to the user's own number. */
    public function send(Request $request, OtpService $otp)
    {
        $user = $request->user();

        if (! User::mobileVerificationSupported()) {
            return redirect()->route($user->homeRoute())->with('error', 'Mobile verification is not enabled yet.');
        }

        if ($user->mobileStepDone()) {
            return redirect()->route($user->homeRoute())->with('status', 'Your mobile number is already verified.');
        }

        if ($this->closedWhileAwaitingApproval($user)) {
            return redirect()->route($user->homeRoute());
        }

        if (! $user->mobile) {
            return back()->with('error', 'Add your 10-digit mobile number first.');
        }

        if ($this->fallback()) {
            return $this->sendByEmail($request, $otp);
        }

        $result = $otp->issue($user->mobile, $request->ip(), 'mobile_verification', $user->id);
        $masked = SmsService::mask($user->mobile);

        if (MobileOtpEmailBridge::enabled()) {
            if ($result === OtpService::ISSUED) {
                AuditLog::record('auth.mobile_otp_test_issued', $user, ['simulated' => true, 'method' => 'email_bridge', 'sms_sent' => false]);

                // Always this page, as the Verify button on My Profile has no field to fill.
                return redirect()->route('verification.mobile.notice')
                    ->with('status', __('A 6-digit code was emailed to :email and filled in below. No SMS was sent.', ['email' => DeliveryLog::maskEmail($user->email)]));
            }

            return back()->with('error', $result === OtpService::SEND_FAILED
                ? 'The test code could not be emailed, so nothing was filled in. This site emails approved addresses only.'
                : OtpService::userMessage($result));
        }

        return back()->with($result === OtpService::ISSUED
            ? ['status' => "A 6-digit code has been sent to {$masked}. Enter it below within ".OtpService::ttl().' minutes.']
            : ['error' => OtpService::userMessage($result)]);
    }

    /** Check the submitted code and persist the verified state. */
    public function verify(Request $request, OtpService $otp)
    {
        $user = $request->user();

        $request->validate(['otp' => 'required|digits:6']);

        if (! User::mobileVerificationSupported() || ! $user->mobile) {
            return back()->with('error', 'Mobile verification is not available.');
        }

        if ($user->mobileStepDone()) {
            return redirect()->route($user->homeRoute())->with('status', 'Your mobile number is already verified.');
        }

        $proven = $user->mobile;
        $bridge = MobileOtpEmailBridge::enabled();

        if ($this->fallback()) {
            return $this->verifyByEmail($request, $otp, $proven);
        }

        // A code from the bridge counts only in the session it was issued to.
        $result = $bridge && ! MobileOtpEmailBridge::issuedHere($user)
            ? OtpService::EXPIRED
            : $otp->verify($proven, $request->input('otp'), $bridge);

        if ($result !== OtpService::OK) {
            AuditLog::record('auth.otp_failed', $user, ['flow' => 'mobile_verification', 'reason' => $result]);

            return back()->withErrors(['otp' => match ($result) {
                OtpService::BLOCKED => 'Too many incorrect attempts. Please request a new code later.',
                OtpService::EXPIRED => 'The code is invalid or has expired. Please request a new one.',
                default             => $otp->status($user->mobile)['attempts_left'] > 0
                    ? __('Incorrect code. You have :count attempts left.', ['count' => $otp->status($user->mobile)['attempts_left']])
                    : 'Too many incorrect attempts. Please request a new code later.',
            }]);
        }

        // A code from an email proves nothing about the phone, so the status says simulated.
        if ($bridge) {
            $otp->clear($proven, OtpService::FLOW_MOBILE_VERIFICATION);

            if (! MobileOtpEmailBridge::complete($user, $proven)) {
                return back()->with('error', 'Your number changed during this step. Please request a new code.');
            }

            AuditLog::record('auth.mobile_otp_test_passed', $user, ['simulated' => true, 'method' => User::METHOD_DEV_EMAIL, 'sms_sent' => false, 'mobile_verified' => false]);

            // complete() wrote to the database, and the next page depends on what it wrote.
            $user->refresh();

            return redirect()->route($this->landingRoute($user))
                ->with('status', 'Email: Verified. Mobile: Development Verified. Account: Locally verified. No SMS was sent.');
        }

        // Only the number that was proven: a correction in another tab must not inherit this code.
        if (! User::whereKey($user->id)->where('mobile', $proven)->update(['mobile_verified_at' => now()] + User::smsVerifiedAttributes())) {
            $otp->clear($proven, OtpService::FLOW_MOBILE_VERIFICATION);

            return back()->with('error', 'Your number changed during this step. Please request a new code.');
        }

        $user->refresh();
        $otp->clear($user->mobile, OtpService::FLOW_MOBILE_VERIFICATION);
        AuditLog::record('auth.mobile_verified', $user);
        app(VerificationLedger::class)->record($user, VerificationLedger::PURPOSE_MOBILE, $user->mobile_verification_method ?: User::METHOD_SMS, 'sms', $user->mobile_verification_method === User::METHOD_MOCK_SMS ? null : User::SMS_DELIVERED);
        app(DeliveryLog::class)->confirm('sms', 'mobile_verification', $user->id);
        $user->notify(new SecurityAlertNotification('mobile_verified'));

        return redirect()->route($user->homeRoute())
            ->with('status', $user->isNotary()
                ? 'Your mobile number has been verified. You can now reset your password by SMS.'
                : 'Your mobile number has been verified. Your account is now fully active.');
    }

    /** SMS is unavailable and the email fallback is on. The development bridge, when open, comes first. */
    private function fallback(): bool
    {
        return VerificationStatus::mode() === VerificationStatus::MODE_EMAIL_FALLBACK;
    }

    /**
     * The mobile-purpose code, to the signed-in account's own verified address. No SMS provider is
     * called. Safe Preview delivers nothing, so no code is made there.
     */
    private function sendByEmail(Request $request, OtpService $otp)
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        if (Platform::mailMode() === 'safe_preview') {
            return back()->with('error', "We couldn't send your verification code. Please try again later.");
        }

        if (! DeliveryLog::allowed('mail', $user->email)) {
            return back()->with('error', "We couldn't send your verification code. Please try again later.");
        }

        [$result, $code] = $otp->issueMobileFallbackCode($user, $request->ip());

        if ($result !== OtpService::ISSUED) {
            return back()->with('error', OtpService::userMessage($result));
        }

        $user->notify(new MobileEmailFallbackCodeNotification($code, (string) $user->mobile, now()->addMinutes(OtpService::ttl())));
        AuditLog::record('auth.mobile_email_fallback_issued', $user, ['sms_sent' => false, 'sent_to' => 'verified_email'], $user->id);

        return redirect()->route('verification.mobile.notice')->with('status', __('SMS is temporarily unavailable, so your 6-digit code is being emailed to :email. Enter it below within :minutes minutes. No text message was sent.', ['email' => DeliveryLog::maskEmail($user->email), 'minutes' => OtpService::ttl()]));
    }

    /** Checks the emailed mobile code and records the email fallback for the number that was checked. */
    private function verifyByEmail(Request $request, OtpService $otp, string $proven)
    {
        $user = $request->user();
        $result = $otp->verifyMobileFallbackCode($user, (string) $request->input('otp'));

        if ($result !== OtpService::OK) {
            AuditLog::record('auth.otp_failed', $user, ['flow' => OtpService::FLOW_MOBILE_EMAIL_FALLBACK, 'reason' => $result]);
            $left = $otp->status($proven, OtpService::FLOW_MOBILE_EMAIL_FALLBACK)['attempts_left'];

            return back()->withErrors(['otp' => match (true) {
                $result === OtpService::BLOCKED => 'Too many incorrect attempts. Please request a new code later.',
                $result === OtpService::EXPIRED => 'The code is invalid or has expired. Please request a new one.',
                $left > 0 => __('Incorrect code. You have :count attempts left.', ['count' => $left]),
                default => 'Too many incorrect attempts. Please request a new code later.',
            }]);
        }

        $otp->clear($proven, OtpService::FLOW_MOBILE_EMAIL_FALLBACK);

        // Only the number that was checked. mobile_verified_at is deliberately left alone.
        $updated = User::whereKey($user->id)->where('mobile', $proven)->update([
            'mobile_verification_method' => User::METHOD_EMAIL_FALLBACK,
            'mobile_verification_status' => User::STATUS_FALLBACK_COMPLETED,
            'mobile_sms_status' => User::SMS_NOT_SENT,
            'mobile_fallback_verified_at' => now(),
            'mobile_fallback_number' => $proven,
        ]);

        if (! $updated) {
            return back()->with('error', 'Your number changed during this step. Please request a new code.');
        }

        $user->refresh();
        app(VerificationLedger::class)->record($user, VerificationLedger::PURPOSE_MOBILE, User::METHOD_EMAIL_FALLBACK, 'email', User::SMS_NOT_SENT);
        app(DeliveryLog::class)->confirm('mail', 'mobile_email_fallback_code', $user->id);
        AuditLog::record('auth.mobile_email_fallback_verified', $user, ['sms_sent' => false, 'mobile_verified_by_sms' => false], $user->id);

        return redirect()->route($this->landingRoute($user))
            ->with('status', 'Mobile step complete: Verified via Email Fallback. No SMS was sent, so your number is not confirmed by SMS.');
    }

    /** A Notary awaiting approval lands on the waiting page, unless a previous account comes first. */
    private function landingRoute(User $user): string
    {
        return $this->awaitingApproval($user) ? ($user->accountChoiceRoute() ?? $user->homeRoute()) : $user->homeRoute();
    }

    private function awaitingApproval(User $user): bool
    {
        return $user->isNotary() && ! $user->isApprovedNotary();
    }

    /**
     * Anyone can register as a notary, and each SMS code is a paid message, so a Notary awaiting
     * approval gets no SMS. A local bridge code, or an email fallback code to their own verified
     * address, costs nothing and is allowed. Completing either step never approves the account.
     */
    private function closedWhileAwaitingApproval(User $user): bool
    {
        return $this->awaitingApproval($user) && ! MobileOtpEmailBridge::enabled() && ! $this->fallback();
    }

    /**
     * Fix a mistyped number before it is verified. A verified number changes through
     * the profile only. The password is required here too: otherwise a stolen session
     * could set its own number, prove it, and then reset the password by SMS.
     */
    public function changeNumber(Request $request)
    {
        $user = $request->user();

        if (! User::mobileVerificationSupported()) {
            return redirect()->route($user->homeRoute())->with('error', 'Mobile verification is not enabled yet.');
        }

        if ($user->hasVerifiedMobile()) {
            return redirect()->route($user->homeRoute())->with('error', 'Your number is already verified. Use your profile page to change it.');
        }

        // An array here would break normalizeMobile(), so only a string reaches it.
        $typed = $request->input('mobile');
        $request->merge(['mobile' => SmsService::normalizeMobile(is_string($typed) ? $typed : null) ?? $typed]);

        $data = $request->validate([
            'mobile' => ['required', 'string', 'digits:10', Rule::unique('users', 'mobile')->ignore($user->id)],
            'current_password' => 'required|string',
        ], [
            'mobile.digits' => 'Enter a valid 10-digit Indian mobile number.',
            'mobile.unique' => 'This mobile number is already used by another account.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            AuditLog::record('user.mobile_change_denied', $user, ['reason' => 'wrong_password']);

            return back()->withErrors(['current_password' => 'Your current password is incorrect.']);
        }

        if ($data['mobile'] === $user->mobile) {
            return back()->with('status', 'That is already the number on your account. Send the code to verify it.');
        }

        // Each new number starts with clean per-number limits, so changes are capped per account.
        if (RateLimiter::tooManyAttempts($key = 'mobile-change:'.$user->id, self::MAX_NUMBER_CHANGES_PER_DAY)) {
            return back()->with('error', 'You have changed your number too many times today. Please try again tomorrow.');
        }

        RateLimiter::hit($key, 86400);

        // Only these two columns change; anything else in the request is ignored.
        $user->forceFill(['mobile' => $data['mobile'], 'mobile_verified_at' => null])->save();
        AuditLog::record('user.mobile_changed', $user);
        $user->notify(new SecurityAlertNotification('mobile_changed'));

        return back()->with('status', 'Mobile number updated to ******'.substr($data['mobile'], -4).'. Send the code to verify it.');
    }
}
