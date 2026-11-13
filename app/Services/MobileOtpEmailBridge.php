<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\MobileOtpTestNotification;
use App\Support\Platform;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Local testing only, while real SMS waits for DLT. The code OtpService generated goes by
 * email instead of SMS and is filled into the mobile page for the session that asked for it.
 *
 * Local Gmail mode is required as well as local and debug, because the real-database Apache
 * site is also local and debug. That mode is set only by Start-Local-Gmail.ps1, production
 * ignores it, and it limits mail to approved recipients.
 *
 * Passing here proves nothing about the phone, so mobile_verified_at is never set. A separate
 * simulated status is recorded, and it counts only while this bridge is open. The code is also
 * filled in without the mailbox being read, so the email method is marked as development too.
 *
 * It closes by itself once real SMS is ready. See SmsService::realSmsReady().
 */
class MobileOtpEmailBridge
{
    private const ISSUED = 'mobile_otp_bridge.issued';

    private const AUTO_SENT = 'mobile_otp_bridge.auto_sent';

    public ?string $lastError = null;

    public function __construct(private User $user)
    {
    }

    public static function enabled(): bool
    {
        return app()->environment('local') && config('app.debug') === true && Platform::mailMode() === 'local_gmail'
            && User::verificationStatusSupported() && ! SmsService::realSmsReady();
    }

    /** The bridge for this account, or null when real SMS rules apply. */
    public static function forUser(?int $userId): ?self
    {
        $user = self::enabled() && $userId ? User::find($userId) : null;

        return $user ? new self($user) : null;
    }

    /**
     * Sent now, not queued, as a queued job would hold the plain code in the jobs table.
     * The code reaches the session only after the mail went out.
     */
    public function send(string $code): bool
    {
        if (! DeliveryLog::allowed('mail', $this->user->email)) {
            $this->lastError = 'blocked_by_mail_allowlist';

            return false;
        }

        try {
            $this->user->notifyNow(new MobileOtpTestNotification($code));
        } catch (Throwable $e) {
            $this->lastError = 'mail:'.class_basename($e);
            Log::warning('Development test code could not be emailed', ['error' => class_basename($e)]);

            return false;
        }

        session()->put(self::ISSUED, ['user' => $this->user->id, 'mobile' => $this->user->mobile, 'code' => Crypt::encryptString($code)]);

        return true;
    }

    /** True when this session asked for the current code, for this account and number. */
    public static function issuedHere(User $user): bool
    {
        return self::matches(session(self::ISSUED), $user);
    }

    /** The code for the page, only for the session and account it was issued to. */
    public static function prefill(User $user, bool $codeIsActive): ?string
    {
        if (! $codeIsActive || ! self::issuedHere($user)) {
            return null;
        }

        try {
            return Crypt::decryptString(session(self::ISSUED)['code']);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Records the simulation for the number that was checked. A genuine verification already
     * on the account is kept, and mobile_verified_at is never written here.
     */
    public static function complete(User $user, string $mobile): bool
    {
        $attributes = $user->hasVerifiedMobile() ? [] : [
            'mobile_verification_method' => User::METHOD_DEV_EMAIL,
            'mobile_verification_status' => User::STATUS_SIMULATED,
            'mobile_simulated_at' => now(),
        ];

        if (! $user->hasVerifiedEmail()) {
            $attributes += ['email_verified_at' => now(), 'email_verification_method' => User::METHOD_DEV_EMAIL];
        }

        session()->forget(self::ISSUED);

        return $attributes === [] || User::whereKey($user->id)->where('mobile', $mobile)->update($attributes) === 1;
    }

    /** True once per session, so a failed send cannot resubmit itself. */
    public static function shouldAutoSend(array $otp): bool
    {
        if (! self::enabled() || $otp['active'] || $otp['cooldown'] > 0 || $otp['blocked'] > 0 || session(self::AUTO_SENT)) {
            return false;
        }

        session()->put(self::AUTO_SENT, true);

        return true;
    }

    private static function matches(mixed $entry, User $user): bool
    {
        return self::enabled() && is_array($entry) && $user->mobile
            && ($entry['user'] ?? null) === $user->id && ($entry['mobile'] ?? null) === $user->mobile;
    }
}
