<?php

namespace App\Support;

use App\Models\User;
use App\Services\MobileOtpEmailBridge;
use App\Services\SmsService;

/**
 * The one place that says what an account has proven, and which mobile flow is active.
 * Decided on the server from configuration and the provider check, never from a request.
 */
class VerificationStatus
{
    public const MODE_DEVELOPMENT = 'development_email_otp';

    public const MODE_REAL_SMS = 'real_sms';

    /** SMS is not ready, so the mobile step's code goes to the verified email. No SMS provider is called. */
    public const MODE_EMAIL_FALLBACK = 'email_fallback';

    public const MODE_SMS_NOT_READY = 'sms_not_ready';

    public static function mode(): string
    {
        return match (true) {
            SmsService::realSmsReady() => self::MODE_REAL_SMS,
            MobileOtpEmailBridge::enabled() => self::MODE_DEVELOPMENT,
            config('services.msg91.email_fallback') === true && User::mobileFallbackSupported() => self::MODE_EMAIL_FALLBACK,
            default => self::MODE_SMS_NOT_READY,
        };
    }

    /**
     * @return array{email: string, mobile: string, account: string, method: string} Each value
     *                                                                               is a lang key
     */
    public static function for(User $user): array
    {
        return [
            'email' => match (true) {
                ! $user->hasVerifiedEmail() => 'Pending',
                $user->email_verification_method === User::METHOD_EMAIL_CODE => 'Verified (Email OTP)',
                $user->email_verification_method === User::METHOD_ADMIN_MANUAL => 'Verified Manually by Admin',
                default => 'Verified',
            },
            'mobile' => match (true) {
                $user->hasVerifiedMobile() => 'Mobile Verified',
                $user->mobileSimulationAccepted() => 'Development Verified',
                $user->mobileFallbackVerified() => 'Verified via Email Fallback',
                $user->mobileManualVerified() => 'Verified Manually by Admin',
                default => 'Pending',
            },
            'account' => match (true) {
                self::productionVerified($user) => 'Production verified',
                self::manualVerified($user) => 'Verified with Admin assistance',
                self::fallbackVerified($user) => 'Verified with email fallback',
                self::locallyVerified($user) => 'Locally verified',
                default => 'Pending',
            },
            'method' => match (true) {
                $user->hasVerifiedMobile() && $user->mobile_verification_method === User::METHOD_MOCK_SMS => 'Mock SMS, no phone reached',
                $user->hasVerifiedMobile() => 'SMS OTP',
                $user->mobileSimulationAccepted() => 'Development email OTP',
                $user->mobileFallbackVerified() => 'Email fallback, no SMS sent',
                $user->mobileManualVerified() => 'Admin manual check, no SMS sent',
                default => 'Not verified yet',
            },
        ];
    }

    /**
     * The badges on Admin > User Verification Management: [lang key, badge class] for email, mobile
     * and temporary access. Each method keeps its own wording, so a manual check never reads as an
     * OTP or an SMS.
     *
     * @return array{email: array{0: string, 1: string}, mobile: array{0: string, 1: string}, access: array{0: string, 1: string}|null}
     */
    public static function badges(User $user): array
    {
        $email = match (true) {
            ! $user->hasVerifiedEmail() => ['Pending', 'en-badge-pending'],
            $user->email_verification_method === User::METHOD_ADMIN_MANUAL => ['Verified Manually by Admin', 'en-badge-assigned'],
            $user->email_verification_method === User::METHOD_EMAIL_CODE => ['Verified via Email OTP', 'en-badge-approved'],
            $user->email_verification_method === User::METHOD_DEV_EMAIL => ['Development Verified', 'en-badge-pending'],
            default => ['Verified via Email Link', 'en-badge-approved'],
        };

        $mobile = match (true) {
            $user->hasVerifiedMobile() && $user->mobile_verification_method === User::METHOD_MOCK_SMS => ['Mock SMS, no phone reached', 'en-badge-pending'],
            $user->hasVerifiedMobile() => ['Verified via SMS', 'en-badge-approved'],
            $user->mobileManualVerified() => ['Verified Manually by Admin', 'en-badge-assigned'],
            $user->mobileFallbackVerified() => ['Verified via Email Fallback', 'en-badge-assigned'],
            $user->mobileSimulationAccepted() => ['Development Verified', 'en-badge-pending'],
            blank($user->mobile) => ['No number on the account', 'en-badge-pending'],
            default => ['Pending', 'en-badge-pending'],
        };

        $latest = \App\Models\TemporaryAccessGrant::supported()
            ? \App\Models\TemporaryAccessGrant::where('user_id', $user->id)->latest('id')->first()
            : null;

        $access = match ($latest?->status()) {
            null => null,
            \App\Models\TemporaryAccessGrant::STATUS_ACTIVE, \App\Models\TemporaryAccessGrant::STATUS_SCHEDULED => ['Temporary Access Active', 'en-badge-correction'],
            \App\Models\TemporaryAccessGrant::STATUS_REVOKED => ['Temporary Access Revoked', 'en-badge-rejected'],
            default => ['Temporary Access Expired', 'en-badge-rejected'],
        };

        return ['email' => $email, 'mobile' => $mobile, 'access' => $access];
    }

    /** Verified, with at least one step confirmed by an Admin instead of by the account holder. */
    public static function manualVerified(User $user): bool
    {
        return $user->hasVerifiedEmail() && ($user->emailManuallyVerified() || $user->mobileManualVerified())
            && ($user->hasVerifiedMobile() || $user->mobileManualVerified() || $user->mobileFallbackVerified());
    }

    /** What is known about SMS for this account's number. A lang key. Never claims a delivery it has not seen. */
    public static function sms(User $user): string
    {
        return match (true) {
            $user->hasVerifiedMobile() && $user->mobile_verification_method === User::METHOD_MOCK_SMS => 'Mock SMS, no phone reached',
            $user->hasVerifiedMobile() => 'Delivered and verified by SMS',
            $user->mobileFallbackVerified() || self::mode() === self::MODE_EMAIL_FALLBACK => 'Not Sent / Temporarily Unavailable',
            default => 'Not sent',
        };
    }

    /**
     * Email and mobile proven for real by the account holder. A simulation, a mock, an email fallback
     * or an Admin manual check never reaches this.
     */
    public static function productionVerified(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && ! in_array($user->email_verification_method, [User::METHOD_DEV_EMAIL, User::METHOD_ADMIN_MANUAL], true)
            && $user->hasVerifiedMobile()
            && $user->mobile_verification_method !== User::METHOD_MOCK_SMS;
    }

    /** Email verified, and the mobile step done through the email fallback. Not SMS proof. */
    public static function fallbackVerified(User $user): bool
    {
        return $user->hasVerifiedEmail() && ! $user->hasVerifiedMobile() && $user->mobileFallbackVerified();
    }

    public static function locallyVerified(User $user): bool
    {
        return $user->hasVerifiedEmail() && ($user->hasVerifiedMobile() || $user->mobileSimulationAccepted());
    }
}
