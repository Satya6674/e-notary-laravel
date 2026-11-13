<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Notifications\SecurityAlertNotification;
use App\Services\DeliveryLog;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Changing a registered mobile number.
 *
 * The number is a recovery channel, so a logged-in session alone is not enough:
 * the current password is required, then the new number must prove itself by SMS
 * code. Until then it waits in users.pending_mobile and the old number stays in
 * force, so an abandoned change costs the person nothing. Audit entries record the
 * action only, never either number.
 */
class MobileChangeController extends Controller
{
    private const MAX_CHANGES_PER_DAY = 3;

    /** Step 1: re-check the password, hold the new number and send it a code. */
    public function start(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $request->user();

        // An array here would break normalizeMobile(), so only a string reaches it.
        $typed = $request->input('mobile');
        $request->merge(['mobile' => SmsService::normalizeMobile(is_string($typed) ? $typed : null) ?? $typed]);

        $data = $request->validate([
            'mobile'           => ['required', 'string', 'digits:10', Rule::unique('users', 'mobile')->ignore($user->id)],
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
            return back()->withErrors(['mobile' => 'That is already the number on your account.']);
        }

        // Each new number starts with clean per-number limits, so changes are capped per account.
        if (RateLimiter::tooManyAttempts($key = 'mobile-change:'.$user->id, self::MAX_CHANGES_PER_DAY)) {
            AuditLog::record('user.mobile_change_denied', $user, ['reason' => 'daily_cap']);

            return back()->with('error', 'You have changed your number too many times today. Please try again tomorrow.');
        }

        $result = $otp->issue($data['mobile'], $request->ip(), 'mobile_change', $user->id);

        if ($result !== OtpService::ISSUED) {
            return back()->with('error', OtpService::userMessage($result));
        }

        RateLimiter::hit($key, 86400);

        $user->forceFill(['pending_mobile' => $data['mobile'], 'pending_mobile_requested_at' => now()])->save();

        AuditLog::record('user.mobile_change_requested', $user);
        $user->notify(new SecurityAlertNotification('mobile_change_requested'));

        return redirect()->route('user.profile')
            ->with('status', 'A 6-digit code has been sent to the new number. Your current number stays active until you enter it.');
    }

    /** Step 2: the code proves the new number, and only now does it replace the old one. */
    public function verify(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $request->user();

        $request->validate(['otp' => 'required|digits:6']);

        $pending = $user->pendingMobile();

        if (! $pending) {
            return redirect()->route('user.profile')->with('error', 'This change has expired. Please start again.');
        }

        $result = $otp->verify($pending, $request->input('otp'), false, OtpService::FLOW_MOBILE_CHANGE);

        if ($result !== OtpService::OK) {
            AuditLog::record('user.mobile_change_denied', $user, ['reason' => $result]);

            return back()->withErrors(['otp' => match ($result) {
                OtpService::BLOCKED => 'Too many incorrect attempts. Please request a new code later.',
                OtpService::EXPIRED => 'The code is invalid or has expired. Please request a new one.',
                default             => 'Incorrect code. Please try again.',
            }]);
        }

        $previous = $user->mobile;

        if (! $this->activate($user, $pending)) {
            // The number is someone else's now: only this change's codes go, never theirs.
            $otp->clear($pending, OtpService::FLOW_MOBILE_CHANGE);
            $user->forceFill(['pending_mobile' => null, 'pending_mobile_requested_at' => null])->save();

            return redirect()->route('user.profile')->with('error', 'This mobile number is already used by another account.');
        }

        $otp->clear($pending, OtpService::FLOW_MOBILE_CHANGE);

        // A reset already under way on the old number must not outlive the change.
        if ($previous) {
            $otp->clear($previous);
        }

        AuditLog::record('user.mobile_changed', $user, ['changed' => ['mobile'], 'proven_by' => 'sms_otp']);
        app(DeliveryLog::class)->confirm('sms', 'mobile_change', $user->id);
        $user->notify(new SecurityAlertNotification('mobile_changed'));
        $user->notify(new PortalNotification('Mobile number changed', 'Account #'.$user->id, route('user.profile')));

        return redirect()->route('user.profile')->with('status', 'Your mobile number has been changed and verified.');
    }

    /** Another account may have taken the number since step 1, so check again under lock. */
    private function activate(User $user, string $mobile): bool
    {
        try {
            return DB::transaction(function () use ($user, $mobile) {
                // Same request still waiting, and nobody else has the number. Either can change meanwhile.
                $waiting = User::whereKey($user->id)->lockForUpdate()->value('pending_mobile') === $mobile;

                if (! $waiting || User::where('mobile', $mobile)->whereKeyNot($user->id)->lockForUpdate()->exists()) {
                    return false;
                }

                $attributes = ['mobile' => $mobile, 'pending_mobile' => null, 'pending_mobile_requested_at' => null];

                if (User::mobileVerificationSupported()) {
                    $attributes += ['mobile_verified_at' => now()] + User::smsVerifiedAttributes();
                }

                return $user->forceFill($attributes)->save();
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function heldByAnother(string $mobile, User $user): bool
    {
        return User::where('mobile', $mobile)->whereKeyNot($user->id)->exists();
    }

    /** Send another code to the number held in step 1, under the usual limits. */
    public function resend(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $request->user();
        $pending = $user->pendingMobile();

        if (! $pending) {
            return redirect()->route('user.profile')->with('error', 'This change has expired. Please start again.');
        }

        if ($this->heldByAnother($pending, $user)) {
            $user->forceFill(['pending_mobile' => null, 'pending_mobile_requested_at' => null])->save();

            return redirect()->route('user.profile')->with('error', 'This mobile number is already used by another account.');
        }

        $result = $otp->issue($pending, $request->ip(), 'mobile_change', $user->id);

        return back()->with(...($result === OtpService::ISSUED
            ? ['status', 'A new code has been sent to the new number.']
            : ['error', OtpService::userMessage($result)]));
    }

    public function cancel(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $request->user();

        if ($user->pending_mobile) {
            // Codes for a number someone else now holds are theirs, not this request's.
            if (! $this->heldByAnother($user->pending_mobile, $user)) {
                $otp->clear($user->pending_mobile, OtpService::FLOW_MOBILE_CHANGE);
            }

            $user->forceFill(['pending_mobile' => null, 'pending_mobile_requested_at' => null])->save();
            AuditLog::record('user.mobile_change_cancelled', $user);
        }

        return redirect()->route('user.profile')->with('status', 'The mobile number change was cancelled.');
    }
}
